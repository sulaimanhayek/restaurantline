<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\AuthenticateAgent;
use App\Models\Restaurant;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\ElevenLabsException;
use App\Services\ElevenLabs\Provisioner;
use App\Services\ElevenLabs\ProvisioningReport;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * The command that connects this application to a phone line.
 *
 * Run it after the first `migrate --seed`, and again after anything that
 * changes what the agent can do or say: a new tool route, an edit to the tone
 * of voice, a rotated `AGENT_API_TOKEN`. It is safe to run repeatedly — see
 * `Provisioner` for how — and it is safe to run before believing any of it,
 * with `--dry-run`.
 *
 * Nothing here writes to `.env`. The one-time webhook secret is printed for a
 * person to copy, and printed loudly, because a command that edits a
 * developer's environment file is a command that eventually eats one.
 */
final class ProvisionCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'kitchenline:provision
        {--dry-run : Print exactly what would be sent to ElevenLabs, and send nothing}
        {--phone-number= : Also import this Twilio number and point it at the agent}
        {--restaurant= : Slug of the restaurant to provision, if this install has more than one}
        {--force : Skip the confirmation in production}';

    protected $description = 'Create or update the ElevenLabs agent, its nine tools and its post-call webhook';

    public function handle(Provisioner $provisioner, ElevenLabsClient $client): int
    {
        $restaurant = $this->restaurant();

        if ($restaurant === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Restaurant</>', $restaurant->name);
        $this->components->twoColumnDetail('<fg=gray>ElevenLabs driver</>', $client->name());
        $this->components->twoColumnDetail('<fg=gray>Tools will be called at</>', (string) config('app.url'));
        $this->newLine();

        $this->warnAboutUnreachableUrls();
        $this->warnAboutTheExampleToken();

        if ($this->option('dry-run')) {
            $this->dryRun($provisioner, $restaurant);

            return self::SUCCESS;
        }

        if ($client->name() === 'fake') {
            $this->components->warn(
                'ELEVENLABS_DRIVER is "fake", so this will not touch a real workspace. '
                .'Everything below is what would have been sent.',
            );
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $report = $provisioner->provision($restaurant);

            $this->report($report);

            if (is_string($this->option('phone-number'))) {
                $this->attachPhoneNumber($provisioner, $restaurant);
            }
        } catch (ElevenLabsException $exception) {
            $this->failure($exception);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------

    private function restaurant(): ?Restaurant
    {
        $slug = $this->option('restaurant');

        if (is_string($slug) && $slug !== '') {
            $found = Restaurant::query()->where('slug', $slug)->first();

            if ($found === null) {
                $this->components->error(sprintf('No restaurant with the slug "%s".', $slug));
            }

            return $found;
        }

        $restaurant = Restaurant::query()->orderBy('id')->first();

        if ($restaurant === null) {
            $this->components->error('There is no restaurant to provision. Run `php artisan migrate --seed` first.');
        }

        return $restaurant;
    }

    /**
     * The commonest reason a freshly provisioned agent does nothing.
     *
     * ElevenLabs calls the tool URLs from its own infrastructure, so an APP_URL
     * only this machine can resolve produces an agent that answers the phone,
     * sounds perfect, and cannot look up a single dish. Worth interrupting for.
     */
    private function warnAboutUnreachableUrls(): void
    {
        $url = (string) config('app.url');

        foreach (['localhost', '127.0.0.1', '0.0.0.0', '.local', '.test'] as $needle) {
            if (! str_contains($url, $needle)) {
                continue;
            }

            $this->components->warn(
                'APP_URL is '.$url.', which ElevenLabs cannot reach. The agent will answer the phone and '
                .'then fail every tool call. Point APP_URL at a public address — an ngrok tunnel is fine for '
                .'testing — and run this again.',
            );

            return;
        }
    }

    /**
     * The token in `.env.example`, still in place at the moment it matters.
     *
     * Provisioning is when a developer stops reading the repo and starts
     * pointing a telephone line at it, so it is the last useful moment to say
     * that the shared secret between ElevenLabs and this application is one
     * anybody can read on GitHub. AuthenticateAgent refuses it in production;
     * this is the warning that arrives before the deploy rather than after.
     */
    private function warnAboutTheExampleToken(): void
    {
        if ((string) config('restaurantline.agent.token', '') !== AuthenticateAgent::PLACEHOLDER_TOKEN) {
            return;
        }

        $this->components->warn(
            'AGENT_API_TOKEN is still the example value from .env.example, which is published in this '
            .'repository. Anyone can read it and create orders. Fine on a laptop; refused outright in '
            .'production. Generate one with: php -r "echo bin2hex(random_bytes(32));"',
        );
    }

    private function dryRun(Provisioner $provisioner, Restaurant $restaurant): void
    {
        $preview = $provisioner->preview($restaurant);

        $this->components->info('Tools');

        foreach ($preview['tools'] as $name => $tool) {
            $schema = $tool['api_schema'];
            $this->components->twoColumnDetail(
                $name,
                sprintf('<fg=gray>%s</> %s', $schema['method'], $schema['url']),
            );
        }

        $agent = $preview['agent'];
        $prompt = $agent['conversation_config']['agent'];

        $this->newLine();
        $this->components->info('Agent');
        $this->components->twoColumnDetail('name', (string) $agent['name']);
        $this->components->twoColumnDetail('llm', (string) $prompt['prompt']['llm']);
        $this->components->twoColumnDetail('voice', (string) ($agent['conversation_config']['tts']['voice_id'] ?? 'workspace default'));
        $this->components->twoColumnDetail('built-in tools', implode(', ', array_keys($prompt['prompt']['built_in_tools'])));
        $this->components->twoColumnDetail('first message', (string) $prompt['first_message']);

        $this->newLine();
        $this->components->info('System prompt');
        $this->line((string) $prompt['prompt']['prompt']);
        $this->newLine();

        $this->components->warn('Nothing was sent. Drop --dry-run to provision for real.');
    }

    private function report(ProvisioningReport $report): void
    {
        $this->components->info('Tools');

        foreach ($report->tools as $name => $action) {
            $this->components->twoColumnDetail($name, $this->describe($action));
        }

        $this->newLine();
        $this->components->info('Agent');
        $this->components->twoColumnDetail('agent', $report->agentId.' '.$this->describe($report->agentAction));
        $this->components->twoColumnDetail('bearer token secret', $report->secretId.' '.$this->describe($report->secretAction));
        $this->components->twoColumnDetail(
            'post-call webhook',
            ($report->webhookId ?? 'none').' '.$this->describe($report->webhookAction),
        );

        $this->newLine();

        if ($report->webhookSecret !== null) {
            $this->webhookSecret($report->webhookSecret);
        }

        $this->components->info('Done. The agent is ready; it needs a phone number to answer.');
    }

    /**
     * The one piece of output that cannot be reproduced.
     *
     * ElevenLabs returns the signing key at creation and never again, and
     * without it the post-call webhook rejects every delivery with a 401 — by
     * design, since a webhook that accepts unsigned posts is an endpoint
     * anybody can write conversations into. Hence the box.
     */
    private function webhookSecret(string $secret): void
    {
        $this->newLine();
        $this->line('  <bg=yellow;fg=black> COPY THIS NOW </>');
        $this->newLine();
        $this->line('  ElevenLabs will not show it again. Put it in your .env:');
        $this->newLine();
        $this->line('      <fg=green>ELEVENLABS_WEBHOOK_SECRET='.$secret.'</>');
        $this->newLine();
        $this->line('  <fg=gray>Without it the post-call webhook rejects every delivery, and you will have</>');
        $this->line('  <fg=gray>no transcripts, no recordings and no record of calls that failed to start.</>');
        $this->newLine();
    }

    /**
     * The one step that changes who answers a ringing telephone.
     *
     * Confirmed by name every time, including in a script, because "I meant to
     * point the test number at it" is not a mistake anybody catches before the
     * first real customer does.
     */
    private function attachPhoneNumber(Provisioner $provisioner, Restaurant $restaurant): void
    {
        $number = (string) $this->option('phone-number');

        $this->newLine();
        $this->components->warn(sprintf(
            'This will make %s ring the agent instead of whatever answers it today.',
            $number,
        ));

        if (! $this->option('force') && ! $this->confirm(sprintf('Point %s at this agent?', $number), false)) {
            $this->components->info('Left the phone number alone.');

            return;
        }

        $id = $provisioner->attachPhoneNumber($restaurant, $number);

        $this->components->twoColumnDetail('phone number', $id.' '.$this->describe('created'));
    }

    private function failure(ElevenLabsException $exception): void
    {
        $this->newLine();
        $this->components->error($exception->getMessage());

        if ($exception->body !== null && $exception->body !== '') {
            $this->line('  <fg=gray>'.str($exception->body)->limit(1000)->toString().'</>');
        }

        $this->newLine();
        $this->components->warn(
            'Nothing was rolled back. Provisioning is safe to run again — anything it created this '
            .'time will be updated rather than duplicated on the next run.',
        );
    }

    private function describe(string $action): string
    {
        return match ($action) {
            'created' => '<fg=green>created</>',
            'updated' => '<fg=cyan>updated</>',
            default => '<fg=gray>already there</>',
        };
    }
}
