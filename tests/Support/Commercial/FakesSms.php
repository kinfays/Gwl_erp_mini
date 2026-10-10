<?php

namespace Tests\Support\Commercial;

use App\Services\Commercial\Sms\SmsGateway;
use Illuminate\Support\Facades\Http;

/** Binds the fake Arkesel gateway and forbids any real HTTP call for the test. */
trait FakesSms
{
    protected FakeSmsGateway $sms;

    protected function fakeSms(): FakeSmsGateway
    {
        Http::preventStrayRequests();
        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);

        return $this->sms;
    }
}
