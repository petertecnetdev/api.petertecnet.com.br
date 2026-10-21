<?php

namespace App\Console\Commands;

use App\Services\CuratorialEditorialPolicy as Editorial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SweepCuratorialContent extends Command
{
    protected $signature = 'curation:editorial {--apply : Persiste alterações (padrão: apenas auditoria)} {--enrichment= : JSON de enriquecimento editorial verificado}';
    protected $description = 'Remove referências promocionais de perfis curatoriais, preserva proveniência interna e enriquece informações confirmadas.';

    public function handle(): int
    {
        if (!Schema::hasTable('curation_provenance')) {
            $this->error('Execute a migration de curation_provenance antes da curadoria.');
            return self::FAILURE;
        }
        $app = DB::table('applications')->where('slug', 'cutinapp')->first(['id']);
        $owner = DB::table('users')->where('email', 'cutinapp@gmail.com')->first(['id']);
        if (!$app || !$owner) {
            $this->error('Conta de curadoria ou aplicação não encontradas.');
            return self::FAILURE;
        }
        $path = (string) $this->option('enrichment');
        $overrides = $path ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($overrides)) throw new \RuntimeException('Manifesto de enriquecimento inválido.');
        $apply = (bool) $this->option('apply');
        $events = DB::table('events as e')->join('establishments as p', 'p.id', '=', 'e.production_id')
            ->where('e.app_id',$app->id)->where('p.app_id',$app->id)->where('p.user_id',$owner->id)
            ->where('e.extra_info','like','%curation%')->select('e.*')->get();
        $productions = DB::table('establishments')->where('app_id',$app->id)
            ->where('user_id',$owner->id)->where('category','production')->get();
        $artists = DB::table('artists')->where('app_id',$app->id)->whereNull('claimed_at')
            ->where(function ($q) use ($owner) {
                $q->where('user_id',$owner->id)->orWhere('created_by_user_id',$owner->id);
            })->get();
        $report=['mode'=>$apply?'apply':'audit','events_scanned'=>count($events),'productions_scanned'=>count($productions),
            'artists_scanned'=>count($artists),'events_changed'=>0,'productions_changed'=>0,'artists_changed'=>0,'provenance'=>0];
        $backup=['events'=>[],'establishments'=>[],'artists'=>[]];
        $now=now();
        $run=function(bool $persist) use($events,$productions,$artists,$overrides,$app,&$backup,&$report,$now) {
            $saveSource=function (string $entity,int $id,?string $url,?string $platform=null,?string $date=null) use($app,$persist,&$report,$now) {
                $url=trim((string)$url);
                if(!filter_var($url,FILTER_VALIDATE_URL))return;
                $host=strtolower((string)parse_url($url, PHP_URL_HOST));
                // Guardar somente fontes de curadoria, não sites de artistas/produtores usados para contato.
                if (!preg_match('/(^|\\.)(shotgun\\.live|sympla\\.com\\.br)$/',$host)) return;
                $report['provenance']++;
                if(!$persist)return;
                DB::table('curation_provenance')->updateOrInsert(
                    ['app_id'=>$app->id,'entity_type'=>$entity,'entity_id'=>$id,'source_hash'=>hash('sha256',$url)],
                    ['source_url'=>$url,'source_platform'=>$platform ?: ucfirst(explode('.', $host)[0]),
                        'verified_on'=>$date ?: null,'updated_at'=>$now,'created_at'=>$now]
                );
            };
            foreach($events as $e) {
                $meta=json_decode((string)$e->extra_info,true);
                if (!is_array($meta))$meta=[];
                $cur=(array)($meta['curation']??[]);
                $saveSource('event',$e->id,$cur['source_url']??null,$cur['source_platform']??null,$cur['verified_on']??null);
                $saveSource('event',$e->id,$e->website);
                $saveSource('event',$e->id,$e->registration_link);
                $raw=(string)$e->description;
                if(preg_match_all('~https?://[^\\s<>"\']+~iu',$raw,$links))
                    foreach($links[0] as $url)$saveSource('event',$e->id,rtrim($url,'.,);'));
                $enrichment=$overrides[$e->id]??null;
                $clean=Editorial::removePromotionalReferences($raw);
                // Uma apresentação atualizada anteriormente não deve duplicar blocos de serviço.
                if (!empty($enrichment['text'])) $summary=$enrichment['text'];
                elseif (isset($cur['editorial_updated_on'])) $summary=$clean;
                else $summary=$clean;
                $shouldBuild = !isset($cur['editorial_updated_on']) || $enrichment;
                $ticketInfo=is_array($enrichment) ? ($enrichment['price']??null) : null;
                if($shouldBuild) {
                    $clean=Editorial::buildEventDescription($e,Editorial::removePromotionalReferences($summary),$ticketInfo);
                }
                unset($cur['source_url'],$cur['source_platform'],$cur['banner_source']);
                if (isset($cur['batch'])) $cur['batch'] = str_ireplace('shotgun', 'curadoria', (string)$cur['batch']);
                $cur['editorial_updated_on']=$cur['editorial_updated_on']??$now->toDateString();
                $meta['curation']=$cur;
                $website=Editorial::isTicketingSource($e->website)?null:$e->website;
                $registration=Editorial::isTicketingSource($e->registration_link)?null:$e->registration_link;
                $values=['description'=>$clean,'website'=>$website,'registration_link'=>$registration,
                    'extra_info'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
                $original=['description'=>$e->description,'website'=>$e->website,'registration_link'=>$e->registration_link,'extra_info'=>$e->extra_info];
                if($values!==$original){
                    $backup['events'][$e->id]=$original;
                    $report['events_changed']++;
                    if($persist) DB::table('events')->where('id',$e->id)->where('app_id',$app->id)->update($values+['updated_at'=>$now]);
                }
            }
            foreach($productions as $p) {
                $other=(string)$p->other_information;
                if(preg_match_all('~https?://[^\\s<>"|]+~iu',$other,$links))
                    foreach($links[0] as $url)$saveSource('production',$p->id,rtrim($url,'.,);'));
                $saveSource('production',$p->id,$p->website_url);
                $clean=Editorial::removePromotionalReferences($p->description);
                $newOther=$other;
                if(preg_match('/(?:Origem|Fonte):|(?:shotgun|sympla)\\./iu',$other)) {
                    $newOther='Perfil publicado pela curadoria da Cutinapp. A administração poderá ser solicitada pelo titular mediante verificação de identidade e titularidade.';
                }
                $website=Editorial::isTicketingSource($p->website_url)?null:$p->website_url;
                $values=['description'=>$clean,'other_information'=>$newOther,'website_url'=>$website];
                $old=['description'=>$p->description,'other_information'=>$p->other_information,'website_url'=>$p->website_url];
                if($values!==$old) {
                    $backup['establishments'][$p->id]=$old;
                    $report['productions_changed']++;
                    if($persist)DB::table('establishments')->where('id',$p->id)->where('user_id',$p->user_id)->update($values+['updated_at'=>$now]);
                }
            }
            foreach($artists as $a) {
                $raw=(string)$a->bio;
                $bio=Editorial::removePromotionalReferences($raw);
                $short=Editorial::removePromotionalReferences($a->short_bio);
                $label=(string)$a->origin_label;
                $label=preg_replace('/\\s*\\((?:Sympla|Shotgun)\\)/iu','',$label);
                $label=preg_replace('/\\b(?:Sympla|Shotgun)\\b/iu','Divulgação pública',$label);
                $website=Editorial::isTicketingSource($a->website_url)?null:$a->website_url;
                $saveSource('artist',$a->id,$a->website_url);
                $metadata = json_decode((string)$a->metadata, true);
                if (!is_array($metadata)) $metadata=[];
                $saveSource('artist',$a->id,$metadata['source_url']??null);
                unset($metadata['source_url'], $metadata['source_platform']);
                $newMetadata = $a->metadata === null && $metadata === [] ? null : json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $values=['bio'=>$bio,'short_bio'=>$short,'origin_label'=>$label,'website_url'=>$website,'metadata'=>$newMetadata];
                $old=['bio'=>$a->bio,'short_bio'=>$a->short_bio,'origin_label'=>$a->origin_label,'website_url'=>$a->website_url,'metadata'=>$a->metadata];
                if($values!==$old){
                    $backup['artists'][$a->id]=$old;
                    $report['artists_changed']++;
                    if($persist)DB::table('artists')->where('id',$a->id)->whereNull('claimed_at')->update($values+['updated_at'=>$now]);
                }
            }
        };
        if ($apply) {
            $run(false);
            if ($report['events_changed']+$report['productions_changed']+$report['artists_changed']===0) {
                $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                return self::SUCCESS;
            }
            foreach(['events_changed','productions_changed','artists_changed','provenance'] as $key) $report[$key]=0;
            $backuppath='/tmp/cutinapp-curatorial-before-'.now()->format('Ymd-His').'.json';
            // Cópia antes da transação, apenas dos campos cuja atualização foi planejada.
            $snapshot=$backup;
            if(file_put_contents($backuppath,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))===false)
                throw new \RuntimeException('Falha no backup: operação cancelada');
            @chmod($backuppath,0600);
            $this->line('Backup antes da alteração: '.$backuppath);
            DB::transaction(fn()=> $run(true),3);
        } else $run(false);
        $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
