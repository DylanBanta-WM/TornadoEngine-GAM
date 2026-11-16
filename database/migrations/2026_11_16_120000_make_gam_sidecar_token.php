<?php

use Illuminate\Database\Migrations\Migration;
use TornadoEngine\Gam\Gam;

return new class extends Migration
{
    // The secret the website and the GAM container share (storage/app/gam/sidecar-token), made
    // before the container starts so nobody has to add one to .env.
    public function up(): void
    {
        Gam::sidecarToken();
    }

    public function down(): void {}
};
