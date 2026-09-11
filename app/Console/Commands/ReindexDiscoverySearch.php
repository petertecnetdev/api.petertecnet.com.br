<?php

namespace App\Console\Commands;

use App\Domain\Discovery\Services\ExternalSearchIndexGateway;
use App\Models\Application;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Item;
use App\Models\Production;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReindexDiscoverySearch extends Command
{
    protected $signature = 'search:reindex {application=cutinapp}';
    protected $description = 'Reconstrói os índices externos do motor de descoberta.';

    public function handle(ExternalSearchIndexGateway $gateway): int
    {
        if (! $gateway->enabled()) {
            $this->warn('Motor externo não configurado. A busca continua usando o fallback de banco.');
            return self::SUCCESS;
        }

        $identifier = (string) $this->argument('application');
        $application = Application::query()
            ->where('slug', $identifier)
            ->when(is_numeric($identifier), fn ($q) => $q->orWhereKey((int) $identifier))
            ->first();

        if (! $application) {
            $this->error('Aplicação não encontrada: '.$identifier);
            return self::FAILURE;
        }

        $appId = (int) $application->id;
        $indexes = [
            'event' => Event::query()
                ->where('app_id', $appId)
                ->publiclyVisible()
                ->get()
                ->map(fn (Event $event) => [
                    'id' => (int) $event->id,
                    'title' => $event->title,
                    'subtitle' => trim(implode(' · ', array_filter([$event->venue, $event->city, $event->uf]))),
                    'search_text' => trim(implode(' ', array_filter([
                        $event->title, $event->description, $event->category, $event->venue,
                        $event->address, $event->neighborhood, $event->formatted_address,
                        $event->establishment_name, $event->organizer_name,
                        json_encode($event->agenda, JSON_UNESCAPED_UNICODE),
                        json_encode($event->additional_info, JSON_UNESCAPED_UNICODE),
                    ]))),
                    'city' => $event->city,
                    'uf' => $event->uf,
                ]),
            'production' => Production::query()
                ->where('app_id', $appId)
                ->where('is_published', true)
                ->where(fn ($q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'))
                ->get()
                ->map(fn (Production $production) => [
                    'id' => (int) $production->id,
                    'title' => $production->name,
                    'subtitle' => trim(implode(' · ', array_filter([$production->fantasy, $production->city, $production->uf]))),
                    'search_text' => trim(implode(' ', array_filter([$production->name, $production->fantasy, $production->description, $production->category, $production->city]))),
                    'city' => $production->city,
                    'uf' => $production->uf,
                ]),
            'artist' => Artist::query()
                ->where('app_id', $appId)
                ->where('is_published', true)
                ->get()
                ->map(fn (Artist $artist) => [
                    'id' => (int) $artist->id,
                    'title' => $artist->stage_name,
                    'subtitle' => trim(implode(' · ', array_filter([$artist->artist_type, $artist->city, $artist->uf]))),
                    'search_text' => trim(implode(' ', array_filter([$artist->stage_name, $artist->bio, implode(' ', (array) $artist->genres), $artist->city]))),
                    'city' => $artist->city,
                    'uf' => $artist->uf,
                ]),
            'user' => $this->users($appId),
            'item' => Item::query()
                ->forApplication($appId)
                ->active()
                ->get()
                ->map(fn (Item $item) => [
                    'id' => (int) $item->id,
                    'title' => $item->name,
                    'subtitle' => trim(implode(' · ', array_filter([$item->category, $item->brand]))),
                    'search_text' => trim(implode(' ', array_filter([$item->name, $item->description, $item->category, $item->subcategory, $item->brand, implode(' ', (array) $item->tags)]))),
                    'city' => null,
                    'uf' => null,
                ]),
        ];

        foreach ($indexes as $type => $documents) {
            $this->line(sprintf('Indexando %s: %d documentos...', $type, $documents->count()));
            if (! $gateway->replaceDocuments($type, $documents)) {
                $this->error('Falha ao atualizar índice: '.$type);
                return self::FAILURE;
            }
        }

        $this->info('Índices de descoberta reconstruídos.');

        return self::SUCCESS;
    }

    private function users(int $appId)
    {
        $query = User::query()
            ->whereNotNull('email_verified_at')
            ->whereHas('applications', fn ($application) => $application
                ->where('applications.id', $appId)
                ->where('application_user.status', 'active'));

        if (Schema::hasTable('user_social_preferences')) {
            $query->whereNotExists(function ($privacy) use ($appId) {
                $privacy->selectRaw('1')
                    ->from('user_social_preferences')
                    ->whereColumn('user_social_preferences.user_id', 'users.id')
                    ->where('user_social_preferences.app_id', $appId)
                    ->where('user_social_preferences.discoverable', false);
            });
        }

        $preferences = Schema::hasTable('user_social_preferences')
            ? DB::table('user_social_preferences')->where('app_id', $appId)->get()->keyBy('user_id')
            : collect();

        return $query->get()->map(function (User $user) use ($preferences) {
            $privacy = $preferences->get((int) $user->id);
            $showCity = ! $privacy || (bool) ($privacy->show_city ?? true);
            $name = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));

            return [
                'id' => (int) $user->id,
                'title' => $name ?: ($user->user_name ?: 'Usuário Cutinapp'),
                'subtitle' => $user->user_name ? '@'.ltrim($user->user_name, '@') : null,
                'search_text' => trim(implode(' ', array_filter([$name, $user->user_name, $user->occupation, $user->about]))),
                'city' => $showCity ? $user->city : null,
                'uf' => $showCity ? $user->uf : null,
            ];
        });
    }
}
