{{-- The per-phone enrollment procedure, printed beside the QR code. Keep in step with docs/assets/mdm.md. --}}
<ol {{ $attributes->class(['mdm-steps']) }}>
    <li>
        <strong>Factory reset the phone.</strong>
        Settings → System → Reset options → Erase all data. No manual app cleanup is needed: the policy decides exactly which apps stay.
    </li>
    <li>
        <strong>Open the QR scanner.</strong>
        At the first “Welcome” screen tap the screen <strong>6 times</strong>, then connect to Wi-Fi when asked.
    </li>
    <li>
        <strong>Scan the code.</strong>
        Point the phone at the QR code and follow the prompts. It downloads the management app and enrolls as a company-owned, fully managed device.
    </li>
    <li>
        <strong>Wait for the policy to finish installing apps.</strong>
        Do not hand the phone over until the required apps have installed. It then appears under Mobile Devices with the status “Compliant”.
    </li>
</ol>
