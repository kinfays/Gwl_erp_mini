<x-guest-layout>
    @php
        $isSetPassword = $isSetPassword ?? false;
        $emailValue = old('email', $request->email);
        $staffIdValue = old('staff_id', $staffId ?? $request->query('staff_id'));
    @endphp

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <input type="hidden" name="email" value="{{ $emailValue }}">

        <!-- Staff ID -->
        <div>
            <x-input-label for="staff_id" :value="__('Staff ID')" />
            <x-text-input id="staff_id" class="block mt-1 w-full" type="text" name="staff_id" :value="$staffIdValue" readonly autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <p class="mt-1 text-xs text-gray-500">{{ __('At least 5 characters, including one letter and one number.') }}</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ $isSetPassword ? __('Set Password') : __('Reset Password') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
