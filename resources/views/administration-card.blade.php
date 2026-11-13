@php
    try {
        $gamConfigured = app(\TornadoEngine\Gam\Gam::class)->configured();
        $gamReachable = true;
    } catch (\Throwable) {
        $gamConfigured = false;
        $gamReachable = false;
    }
@endphp

<x-settings-card :title="__('GAM')">
    @if (session('gam_status'))
        <p class="rounded-md bg-gray-50 dark:bg-gray-900/40 px-3 py-2">{{ session('gam_status') }}</p>
    @endif
    <x-input-error :messages="$errors->get('gam')" />

    @if (! $gamReachable)
        <p>{!! __('The GAM container isn\'t running. Add :token to .env and run :cmd.', ['token' => '<code class="font-mono">GAM_SIDECAR_TOKEN</code>', 'cmd' => '<code class="font-mono">docker compose up -d</code>']) !!}</p>
    @else
        <p>{{ $gamConfigured ? __('GAM\'s files are uploaded.') : __('Upload GAM\'s files to connect it to your Google Workspace.') }}
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

        @if ($gamConfigured)
            <form method="POST" action="{{ route('gam.check') }}" class="flex justify-end">
                @csrf
                <x-secondary-button type="submit">{{ __('Check connection') }}</x-secondary-button>
            </form>
        @endif
    @endif
</x-settings-card>
