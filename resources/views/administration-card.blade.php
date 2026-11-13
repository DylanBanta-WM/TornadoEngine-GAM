@php
    try {
        $gamConfigured = app(\TornadoEngine\Gam\Gam::class)->configured();
        $gamReachable = true;
    } catch (\Throwable) {
        $gamConfigured = false;
        $gamReachable = false;
    }
    $box = 'rounded-md border px-4 py-3';
@endphp

<x-settings-card :title="__('GAM')">
    @if (session('gam_ok'))
        <p class="{{ $box }} border-green-300 dark:border-green-800 bg-green-50 dark:bg-green-900/30 font-medium text-green-800 dark:text-green-300">{{ session('gam_status') }}</p>
    @elseif (session('gam_status'))
        <p class="{{ $box }} border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">{{ session('gam_status') }}</p>
    @endif
    @if ($errors->has('gam'))
        <p class="{{ $box }} border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $errors->first('gam') }}</p>
    @endif

    @if (! $gamReachable)
        <p>{!! __('The GAM container isn\'t running. Add :token to .env and run :cmd.', ['token' => '<code class="font-mono">GAM_SIDECAR_TOKEN</code>', 'cmd' => '<code class="font-mono">docker compose up -d</code>']) !!}</p>
    @elseif ($gamConfigured)
        <p>{{ __('GAM\'s files are uploaded.') }}</p>

        <div class="flex flex-wrap justify-end gap-3">
            <form method="POST" action="{{ route('gam.remove') }}"
                  onsubmit="return confirm(@js(__('Remove GAM\'s files? Modules that use GAM stop working until you upload them again.')))">
                @csrf
                <x-danger-button>{{ __('Remove files') }}</x-danger-button>
            </form>
            <form method="POST" action="{{ route('gam.check') }}">
                @csrf
                <x-secondary-button type="submit">{{ __('Check connection') }}</x-secondary-button>
            </form>
        </div>
    @else
        <p>{{ __('Upload GAM\'s files to connect it to your Google Workspace.') }}
            @if (Route::has('docs.show'))
                <a href="{{ route('docs.show', 'gam') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('How to set up GAM') }}</a>
            @endif
        </p>

        <form method="POST" action="{{ route('gam.config') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @foreach (['oauth2_txt' => 'oauth2.txt', 'oauth2service_json' => 'oauth2service.json', 'client_secrets_json' => 'client_secrets.json'] as $field => $name)
                <div>
                    <x-input-label :for="$field" :value="$name" />
                    <input id="{{ $field }}" type="file" name="{{ $field }}" required class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300 file:me-3 file:min-h-11 file:rounded-md file:border-0 file:bg-gray-100 dark:file:bg-gray-700 file:px-4 file:text-sm file:font-medium">
                    <x-input-error :messages="$errors->get($field)" class="mt-2" />
                </div>
            @endforeach
            <div class="flex justify-end">
                <x-primary-button>{{ __('Upload') }}</x-primary-button>
            </div>
        </form>
    @endif
</x-settings-card>
