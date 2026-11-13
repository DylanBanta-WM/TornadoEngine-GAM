<?php

namespace TornadoEngine\Gam\Http;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
use TornadoEngine\Gam\Gam;

class GamController extends Controller
{
    private const FILES = ['oauth2_txt' => 'oauth2.txt', 'oauth2service_json' => 'oauth2service.json', 'client_secrets_json' => 'client_secrets.json'];

    public function config(Request $request, Gam $gam): RedirectResponse
    {
        $request->validate(array_fill_keys(array_keys(self::FILES), ['required', 'file', 'max:100']));

        $files = [];
        foreach (self::FILES as $field => $name) {
            $files[$name] = $request->file($field)->get();
        }

        try {
            $gam->saveConfig($files);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['gam' => __('Couldn\'t save the files in the GAM container.')]);
        }

        return back()->with('gam_status', __('Saved. Check the connection next.'));
    }

    public function remove(Gam $gam): RedirectResponse
    {
        try {
            $gam->removeConfig();
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['gam' => __('Couldn\'t remove the files from the GAM container.')]);
        }

        return back()->with('gam_status', __('Removed GAM\'s files.'));
    }

    public function limits(Request $request): RedirectResponse
    {
        $limits = $request->validate([
            'slots' => ['required', 'integer', 'min:1', 'max:50'],
            'wait' => ['required', 'integer', 'min:0', 'max:300'],
            'timeout' => ['required', 'integer', 'min:5', 'max:3600'],
        ]);

        Setting::put('gam.limits', json_encode(array_map('intval', $limits)));

        return back()->with('gam_status', __('Saved the limits.'));
    }

    public function check(Gam $gam): RedirectResponse
    {
        try {
            $ok = $gam->check();
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }

        return $ok
            ? back()->with(['gam_status' => __('GAM can reach your Google Workspace.'), 'gam_ok' => true])
            : back()->withErrors(['gam' => __('GAM couldn\'t reach your Google Workspace. Check the files and try again.')]);
    }
}
