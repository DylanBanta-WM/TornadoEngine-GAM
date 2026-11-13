@php
    $code = 'rounded bg-gray-100 dark:bg-gray-900 px-1 py-0.5 font-mono text-[13px] text-gray-900 dark:text-gray-100';
    $link = 'font-medium text-indigo-600 dark:text-indigo-400 underline';
@endphp

<x-setup.header :title="__('Set up GAM')">
    {{ __('Tornado Engine runs GAM in its own container. It needs three files from a working GAM setup.') }}
</x-setup.header>

<div class="mt-6 px-6 sm:px-8 pb-6 sm:pb-8 space-y-4 text-sm text-gray-700 dark:text-gray-300">
    <p>{!! __('If you already use GAM on a computer, the files are in its GAM folder (often :dir): :files.', ['dir' => '<code class="'.$code.'">~/.gam</code>', 'files' => '<code class="'.$code.'">oauth2.txt</code>, <code class="'.$code.'">oauth2service.json</code>, <code class="'.$code.'">client_secrets.json</code>']) !!}</p>
    <p>{!! __('If not, install GAM on a computer and follow :link. It creates the files.', ['link' => '<a href="https://github.com/GAM-team/GAM/wiki/How-to-Install-GAM7" target="_blank" rel="noopener" class="'.$link.'">'.e(__('GAM\'s install guide')).'</a>']) !!}</p>
    <p>{{ __('Then, in Administration, upload the three files on the GAM card and click Check connection.') }}</p>
    <p>{!! __('The GAM container needs a shared password with the website. Add a line like this to .env, then run :cmd:', ['cmd' => '<code class="'.$code.'">docker compose up -d</code>']) !!}</p>
    <x-dns.code>
        GAM_SIDECAR_TOKEN=
    </x-dns.code>
    <p>{!! __('Make the value with :cmd.', ['cmd' => '<code class="'.$code.'">openssl rand -hex 32</code>']) !!}</p>
</div>
