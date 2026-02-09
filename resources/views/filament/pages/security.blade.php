<x-filament-panels::page>
    <div class="space-y-6">
        <!-- 2FA Section -->
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                        <x-heroicon-o-device-phone-mobile class="w-5 h-5" />
                        Two-Factor Authentication
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Add additional security to your account using two-factor authentication.
                    </p>
                </div>
                <div>
                   @if(! $this->user->two_factor_secret && ! $this->user->two_factor_confirmed_at)
                       <div class="flex gap-2">
                           <x-filament::button wire:click="enableTwoFactor">
                               Enable App 2FA
                           </x-filament::button>
                           <x-filament::button color="gray" wire:click="setupSms">
                               Enable SMS 2FA
                           </x-filament::button>
                       </div>
                   @elseif($this->user->two_factor_secret && ! $this->user->two_factor_confirmed_at)
                       {{-- Pending Setup State --}}
                       <div class="flex gap-2 items-center">
                           <span class="text-sm text-yellow-600 dark:text-yellow-500 font-medium">Setup in progress</span>
                           <x-filament::button color="danger" size="sm" wire:click="disableTwoFactor">
                               Cancel Setup
                           </x-filament::button>
                       </div>
                   @else
                       <x-filament::button color="danger" wire:click="disableTwoFactor">
                           Disable 2FA
                       </x-filament::button>
                   @endif
                </div>
            </div>

            <div class="mt-6 border-t border-gray-100 dark:border-gray-800 pt-6">
                <!-- SMS Setup Step -->
                @if($showSmsInput && ! $this->user->two_factor_confirmed_at)
                     <div class="space-y-4">
                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                            Enter your mobile phone number to receive verification codes via SMS.
                        </div>
                        
                        <div class="max-w-md space-y-4">
                            <div class="space-y-2">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Phone Number</label>
                                <div class="flex gap-2">
                                    <x-filament::input.wrapper class="flex-1">
                                        <x-filament::input
                                            type="tel"
                                            wire:model="phone"
                                            placeholder="+15550000000"
                                        />
                                    </x-filament::input.wrapper>
                                    @if(!$showSmsVerify)
                                        <x-filament::button wire:click="sendSmsCode">
                                            Send Code
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                            
                            @if($showSmsVerify)
                                <form wire:submit.prevent="enableSmsTwoFactor" class="space-y-2 pt-4 border-t border-gray-100 dark:border-gray-800">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Verification Code</label>
                                    <div class="flex gap-2">
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                type="text"
                                                wire:model="smsCode"
                                                placeholder="123456"
                                            />
                                        </x-filament::input.wrapper>
                                        <x-filament::button type="submit">
                                            Verify & Enable
                                        </x-filament::button>
                                    </div>
                                    <p class="text-xs text-gray-500">Code sent to logs (simulated).</p>
                                </form>
                            @endif
                        </div>
                    </div>
                @endif

                <!-- QR Code Step -->
                @if($this->user->two_factor_secret && ! $this->user->two_factor_confirmed_at && ! $showSmsInput)
                    <div class="space-y-4">
                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                            To finish enabling two-factor authentication, scan the following QR code using your phone's authenticator application or enter the setup key and provide the generated OTP code.
                        </div>
                        
                        <div class="flex flex-col md:flex-row gap-6 items-center md:items-start">
                            <div class="bg-white p-4 rounded-lg inline-block">
                                {!! $this->user->twoFactorQrCodeSvg() !!}
                            </div>
                            <div class="space-y-4 w-full max-w-sm">
                                <div>
                                    <label class="text-xs text-gray-500 uppercase font-bold tracking-wider">Setup Key</label>
                                    <div class="font-mono text-sm bg-gray-100 dark:bg-gray-800 p-2 rounded selectable">
                                        {{ decrypt($this->user->two_factor_secret) }}
                                    </div>
                                </div>
                                
                                <form wire:submit.prevent="confirmTwoFactor" class="space-y-2">
                                    <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Authentication Code</label>
                                    <div class="flex gap-2">
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                type="text"
                                                wire:model="code"
                                                placeholder="XXX-XXX"
                                            />
                                        </x-filament::input.wrapper>
                                        <x-filament::button type="submit">
                                            Confirm
                                        </x-filament::button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Recovery Codes (Active) -->
                @if($this->user->two_factor_confirmed_at)
                    <div class="space-y-4">
                        <div class="flex items-center gap-2 text-emerald-500 font-medium">
                            <x-heroicon-o-check-circle class="w-5 h-5" />
                            Two-factor authentication is enabled.
                        </div>
                        
                        <div x-data="{ show: false }">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two-factor authentication device is lost.
                            </p>
                            
                            <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg font-mono text-sm grid grid-cols-2 gap-2" >
                                @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                                    <div>{{ $code }}</div>
                                @endforeach
                            </div>

                            <div class="mt-4">
                                <x-filament::button size="sm" color="gray" wire:click="regenerateRecoveryCodes">
                                    Regenerate Recovery Codes
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
