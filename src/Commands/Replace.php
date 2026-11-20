<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\PointDns;
use Grafite\Blacksmith\Actions\ReplaceInstance;
use Grafite\Blacksmith\Support\EnvFile;
use RuntimeException;

class Replace extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:replace
        {instance : The instance to move to a new server}
        {--profile= : The server profile for the new server, defaults to the instance\'s profile}
        {--ip= : Use an existing server at this IP instead of creating one}
        {--private-ip= : The private IP of the existing server}
        {--no-deploy : Skip the first deployment}
        {--cutover : Swap the new server in, moving the scheduler, workers and certificate to it}
        {--finish : Delete the old server once the new one is live}
        {--abort : Delete the new server before cutover}
        {--no-dns : Leave DNS alone during cutover}
        {--force : Delete servers without asking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replace an instance\'s server with a fresh one, such as for a new Ubuntu version, instead of upgrading it in place.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(ReplaceInstance $replace)
    {
        $steps = array_values(array_filter(['cutover', 'finish', 'abort'], fn ($step) => $this->option($step)));

        if (count($steps) > 1) {
            $this->error('Pass only one of --cutover, --finish or --abort.');

            return self::FAILURE;
        }

        $instance = $this->argument('instance');
        $replace->reportUsing($this->reporter());

        try {
            return match ($steps[0] ?? 'build') {
                'build' => $this->build($replace, $instance),
                'cutover' => $this->cutover($replace, $instance),
                'finish' => $this->finish($replace, $instance),
                'abort' => $this->remove($replace, $instance, 'abort', $replace->replacement($instance)),
            };
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }
    }

    protected function build(ReplaceInstance $replace, string $instance): int
    {
        $profile = $this->option('profile') ?? $this->state()->instance($instance)['profile'] ?? 'app';

        $this->ensureProfile($profile);

        try {
            $name = $replace->build($instance, $profile, $this->plan(), $this->option('ip'), $this->option('private-ip'), ! $this->option('no-deploy'));
        } finally {
            $this->printAccessReport();
        }

        $ip = $this->state()->instance($name)['ip_address'];
        $sites = $this->state()->sitesFor($instance);
        $public = $this->state()->publicSitesFor($instance);

        $this->info("{$name} is ready at {$ip} with: ".implode(', ', $sites));

        foreach ($sites as $domain) {
            if ($this->state()->roleFor($instance, $domain) === 'balancer') {
                continue;
            }

            foreach (EnvFile::replaceWarnings($this->blueprints()->env($domain, $instance)) as $warning) {
                $this->warn("{$domain}: {$warning}");
            }
        }

        $certified = array_filter($public, fn ($domain) => $this->blueprints()->site($domain)['lets_encrypt']);

        if ($certified && ! ReplaceInstance::certificateBeforeCutover()) {
            $this->warn("HTTPS on {$ip} fails from the moment DNS moves until its Let's Encrypt certificate is issued (http-01 needs DNS to point at it first). "
                ."To avoid that, set defaults.certificate.verification_method to dns-01 before replacing, or keep the records proxied with Cloudflare's SSL mode on Full.");
        }

        if (empty($public)) {
            $this->line("{$instance} is behind a load balancer so DNS doesn't change, when you are ready run: php artisan blacksmith:replace {$instance} --cutover");

            return self::SUCCESS;
        }

        $dns = $this->laravel->make(PointDns::class);
        $automatic = array_values(array_filter($public, fn ($domain) => $dns->manages($domain)));
        $manual = array_values(array_diff($public, $automatic));

        $this->line('Next:');
        $this->line("  1. Test it, for example add \"{$ip} {$public[0]}\" to your hosts file");
        $this->line("  2. Move any data that lives on {$instance}, such as a local database or uploads");

        if ($manual) {
            $this->line('  3. Point DNS for '.implode(', ', $manual)." at {$ip}");
        }

        $this->line('  '.($manual ? 4 : 3).". php artisan blacksmith:replace {$instance} --cutover");

        if ($automatic) {
            $this->line('     DNS for '.implode(', ', $automatic)." will be pointed at {$ip} on cutover (".PointDns::providerName().').');
        }

        return self::SUCCESS;
    }

    protected function cutover(ReplaceInstance $replace, string $instance): int
    {
        $plan = $this->plan();
        $retired = $replace->cutover($instance, $plan, ! $this->option('no-dns'));
        $old = $this->state()->instance($retired);

        $this->info("{$instance} is now on {$this->state()->instance($instance)['ip_address']}, the old server is kept as {$retired}.");

        if (($until = $replace->drainUntil($instance)) && ! $this->option('force')) {
            $this->line("DNS caches can still send visitors to {$retired} ({$old['ip_address']}) until {$until->toDateTimeString()}, so it keeps serving until then.");
            $this->line("After that, delete it with: php artisan blacksmith:replace {$instance} --finish");

            return self::SUCCESS;
        }

        if ($this->option('force') || $this->confirm("Delete the old server {$retired} ({$old['ip_address']}) now?", false)) {
            $replace->finish($instance, $plan, true);
            $this->info("Deleted {$retired}.");

            return self::SUCCESS;
        }

        $this->line("Once you are happy with {$instance}, delete the old server with: php artisan blacksmith:replace {$instance} --finish");

        return self::SUCCESS;
    }

    protected function finish(ReplaceInstance $replace, string $instance): int
    {
        $retired = $replace->retired($instance);

        if (! $this->option('force')) {
            $replace->ensureDrained($instance);
        }

        return $this->remove($replace, $instance, 'finish', $retired);
    }

    protected function remove(ReplaceInstance $replace, string $instance, string $step, string $target): int
    {
        $ip = $this->state()->instance($target)['ip_address'] ?? 'no IP';

        if (! $this->option('force') && ! $this->confirm("Delete the server {$target} ({$ip})?", false)) {
            $this->warn("Kept {$target}.");

            return self::SUCCESS;
        }

        $step === 'finish'
            ? $replace->finish($instance, $this->plan(), true)
            : $replace->abort($instance, $this->plan());

        $this->info("Deleted {$target}.");

        return self::SUCCESS;
    }
}
