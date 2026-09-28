<x-guest-layout>
    <div class="auth-intro">
        <h2>{{ __('Verify your email address') }}</h2>
        <p>{{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}</p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success" role="status">
            <x-ui.icon name="circle-check" class="alert-icon" />
            <div class="alert-body">{{ __('A new verification link has been sent to the email address you provided during registration.') }}</div>
        </div>
    @endif

    <div class="auth-row">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <x-primary-button>
                <x-ui.icon name="send" />
                {{ __('Resend Verification Email') }}
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="btn btn-ghost">
                <x-ui.icon name="log-out" />
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
