<?php

namespace App\Services\Commercial\Customers;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * A forward-only .xlsx reader whose memory does not depend on the size of the file.
 *
 * Built for the customer list (hundreds of thousands of rows) after measuring the alternatives on a real 18.5k-row export:
 * PhpSpreadsheet needs ~42 s and ~312 MB for it (and ~8 GB for a 500k-row file), OpenSpout's shared-string cache thrashes
 * between temp files (179 s). Here the sheet XML is streamed with XMLReader and the shared strings are written once to a
 * temp file with a packed offset table, so a lookup is O(1) whichever string it is; the first HOT strings (the repeated
 * codes of an export come early) and the most recent ones also stay in memory.
 *
 * Only what the customer list needs is supported: cell values by type (shared string, inline string, string, number,
 * boolean, date), dates recognised from the cell style, and the original row numbers. Merged cells simply read as empty
 * (the value sits in the top-left cell). Sheets are opened by NAME, so a sheet that must never be read (the "Document map"
 * of these exports declares a used range out to column XFC) is never touched.
 */
class XlsxStreamReader
{
    /** Shared strings kept in memory for good (the first ones: repeated codes are first used early in an export). */
    protected const HOT_STRINGS = 20000;

    /** Recently used shared strings beyond the hot head. */
    protected const RECENT_STRINGS = 2000;

    protected ZipArchive $zip;

    /** @var array<int, string> */
    protected array $hot = [];

    /** @var array<int, string> */
    protected array $recent = [];

    /** @var resource|null */
    protected $stringFile = null;

    /** Packed little-endian uint32 offsets of the strings beyond the hot head (plus one final end offset). */
    protected string $offsets = '';

    protected int $stringCount = 0;

    protected bool $stringsLoaded = false;

    protected bool $open = false;

    /** @var array<int, bool>|null cellXfs index => is a date format */
    protected ?array $dateStyles = null;

    /** @var array<string, string>|null sheet name => zip entry */
    protected ?array $sheets = null;

    public function __construct(protected string $path)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The file could not be opened as an Excel workbook.');
        }

        $this->open = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->stringFile) {
            fclose($this->stringFile);
            $this->stringFile = null;
        }

        if ($this->open) {
            $this->open = false;
            $this->zip->close();
        }
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheetEntries());
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheetEntries()[$name]);
    }

    /**
     * Every row of a sheet that has cells, in file order, as original row number => dense list of cell values (index 0 is
     * column A; a gap is null). Dates come back as DateTimeImmutable, numbers as int or float, text as string.
     *
     * @return Generator<int, list<mixed>>
     */
    public function rows(string $sheetName): Generator
    {
        $entry = $this->sheetEntries()[$sheetName] ?? throw new RuntimeException("The workbook has no sheet named \"{$sheetName}\".");

        $this->loadStyles();
        $this->loadSharedStrings();

        $xml = new XMLReader;

        if (! $xml->open('zip://'.$this->path.'#'.$entry, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('The worksheet could not be read.');
        }

        $auto = 0;

        try {
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') {
                    continue;
                }

                $rowNumber = (int) $xml->getAttribute('r') ?: $auto + 1;
                $auto = $rowNumber;
                $cells = [];
                $max = -1;

                if (! $xml->isEmptyElement) {
                    $rowDepth = $xml->depth;
                    $nextColumn = 0;

                    while ($xml->read()) {
                        if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $rowDepth) {
                            break;
                        }

                        if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'c') {
                            continue;
                        }

                        $column = self::columnIndex((string) $xml->getAttribute('r'), $nextColumn);
                        $nextColumn = $column + 1;
                        $value = $this->readCell($xml);

                        if ($value !== null) {
                            $cells[$column] = $value;
                            $max = max($max, $column);
                        }
                    }
                }

                if ($max < 0) {
                    continue;
                }

                $row = array_fill(0, $max + 1, null);

                foreach ($cells as $column => $value) {
                    $row[$column] = $value;
                }

                yield $rowNumber => $row;
            }
        } finally {
            $xml->close();
        }
    }

    /** Column index (0-based) from a cell reference such as "AB12"; $fallback when the reference is missing. */
    public static function columnIndex(string $reference, int $fallback): int
    {
        if ($reference === '' || ! preg_match('/^([A-Za-z]+)/', $reference, $matches)) {
            return $fallback;
        }

        $index = 0;

        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** Excel date serial (1900 system) as a UTC date-time; null when it is not a plausible serial. */
    public static function serialToDate(float $serial): ?DateTimeImmutable
    {
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }

        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC')))
            ->modify("+{$days} days")
            ->modify("+{$seconds} seconds");
    }

    protected function readCell(XMLReader $xml): mixed
    {
        $type = (string) $xml->getAttribute('t');
        $style = $xml->getAttribute('s');
        $isDate = $style !== null && ($this->dateStyles[(int) $style] ?? false);

        if ($xml->isEmptyElement) {
            return null;
        }

        $depth = $xml->depth;
        $raw = null;
        $inline = null;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                break;
            }

            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($xml->localName === 'v') {
                $raw = $xml->readString();
            } elseif ($xml->localName === 'is') {
                $inline = $this->readRichText($xml);
            }
        }

        return match ($type) {
            's' => $raw === null || $raw === '' ? null : $this->sharedString((int) $raw),
            'inlineStr' => $inline,
            'str' => $raw === null || $raw === '' ? null : $raw,
            'b' => $raw === null ? null : $raw === '1',
            'e' => null,
            'd' => $raw === null || $raw === '' ? null : $this->isoDate($raw),
            default => $this->numeric($raw, $isDate),
        };
    }

    protected function numeric(?string $raw, bool $isDate): int|float|DateTimeImmutable|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            return null;
        }

        if ($isDate) {
            return self::serialToDate((float) $raw);
        }

        return ctype_digit($raw) && strlen($raw) < 16 ? (int) $raw : (float) $raw;
    }

    protected function isoDate(string $raw): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** Text of an <is>/<si> element: every <t>, phonetic runs (<rPh>) left out. */
    protected function readRichText(XMLReader $xml): string
    {
        $text = '';
        $depth = $xml->depth;

        if ($xml->isEmptyElement) {
            return '';
        }

        $skipDepth = null;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::END_ELEMENT) {
                if ($xml->depth === $depth) {
                    break;
                }

                if ($skipDepth !== null && $xml->depth === $skipDepth) {
                    $skipDepth = null;
                }

                continue;
            }

            if ($xml->nodeType !== XMLReader::ELEMENT || $skipDepth !== null) {
                continue;
            }

            if ($xml->localName === 'rPh' && ! $xml->isEmptyElement) {
                $skipDepth = $xml->depth;
            } elseif ($xml->localName === 't') {
                $text .= $xml->readString();
            }
        }

        return $text;
    }

    protected function sharedString(int $index): ?string
    {
        if ($index < self::HOT_STRINGS) {
            return $this->hot[$index] ?? null;
        }

        if (isset($this->recent[$index])) {
            return $this->recent[$index];
        }

        $slot = ($index - self::HOT_STRINGS) * 4;

        if ($index >= $this->stringCount || ! $this->stringFile || $slot + 8 > strlen($this->offsets)) {
            return null;
        }

        $pair = unpack('Vfrom/Vto', $this->offsets, $slot);
        $from = $pair['from'];
        $to = $pair['to'];

        fseek($this->stringFile, $from);
        $value = $to > $from ? (string) fread($this->stringFile, $to - $from) : '';

        if (count($this->recent) >= self::RECENT_STRINGS) {
            $this->recent = [];
        }

        return $this->recent[$index] = $value;
    }

    protected function loadSharedStrings(): void
    {
        if ($this->stringsLoaded) {
            return;
        }

        $this->stringsLoaded = true;

        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }

        $xml = new XMLReader;

        if (! $xml->open('zip://'.$this->path.'#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return;
        }

        $this->stringFile = tmpfile() ?: null;
        $position = 0;
        $index = 0;

        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si') {
                continue;
            }

            $text = $this->readRichText($xml);

            if ($index < self::HOT_STRINGS) {
                $this->hot[$index] = $text;
            } else {
                if ($index === self::HOT_STRINGS) {
                    $this->offsets = pack('V', 0);
                }

                fwrite($this->stringFile, $text);
                $position += strlen($text);
                $this->offsets .= pack('V', $position);
            }

            $index++;
        }

        $xml->close();
        $this->stringCount = $index;
    }

    protected function loadStyles(): void
    {
        if ($this->dateStyles !== null) {
            return;
        }

        $this->dateStyles = [];
        $content = $this->zip->getFromName('xl/styles.xml');

        if ($content === false) {
            return;
        }

        $styles = @simplexml_load_string($content);

        if (! $styles instanceof SimpleXMLElement) {
            return;
        }

        $codes = [];

        foreach ($styles->numFmts->numFmt ?? [] as $format) {
            $codes[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }

        $index = 0;

        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $this->dateStyles[$index++] = self::isDateFormat($id, $codes[$id] ?? null);
        }
    }

    public static function isDateFormat(int $id, ?string $code): bool
    {
        if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58)) {
            return true;
        }

        if ($code === null || $code === '' || strcasecmp($code, 'General') === 0) {
            return false;
        }

        // Quoted text, [colour]/[condition] blocks and escaped characters carry no date tokens.
        $stripped = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\.|_.|\*./', '', $code) ?? $code;

        return (bool) preg_match('/[dmyhs]/i', $stripped);
    }

    /** @return array<string, string> */
    protected function sheetEntries(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }

        $workbook = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            throw new RuntimeException('This is not a readable Excel workbook.');
        }

        $targets = [];

        foreach ((simplexml_load_string($rels) ?: new SimpleXMLElement('<r/>'))->Relationship ?? [] as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $this->sheets = [];
        $book = simplexml_load_string($workbook);

        foreach ($book->sheets->sheet ?? [] as $sheet) {
            $relId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $targets[$relId] ?? null;

            if ($target === null) {
                continue;
            }

            $this->sheets[(string) $sheet['name']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }

        return $this->sheets;
    }
}
