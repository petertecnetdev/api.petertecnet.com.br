<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  if(!Schema::hasTable('brazilian_municipalities'))Schema::create('brazilian_municipalities',function(Blueprint $t){$t->unsignedBigInteger('ibge_code')->primary();$t->string('name',120);$t->string('normalized_name',120);$t->char('uf',2);$t->string('slug',140);$t->timestamps();$t->unique(['uf','normalized_name'],'municipality_uf_name_uq');$t->index(['uf','name'],'municipality_uf_name_idx');$t->index('slug','municipality_slug_idx');});
  $now=now();DB::table('brazilian_municipalities')->upsert([
   ['ibge_code'=>3550308,'name'=>'São Paulo','normalized_name'=>'sao paulo','uf'=>'SP','slug'=>'sao-paulo','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>3106200,'name'=>'Belo Horizonte','normalized_name'=>'belo horizonte','uf'=>'MG','slug'=>'belo-horizonte','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>5208707,'name'=>'Goiânia','normalized_name'=>'goiania','uf'=>'GO','slug'=>'goiania','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>2611606,'name'=>'Recife','normalized_name'=>'recife','uf'=>'PE','slug'=>'recife','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>3304557,'name'=>'Rio de Janeiro','normalized_name'=>'rio de janeiro','uf'=>'RJ','slug'=>'rio-de-janeiro','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>4106902,'name'=>'Curitiba','normalized_name'=>'curitiba','uf'=>'PR','slug'=>'curitiba','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>3509502,'name'=>'Campinas','normalized_name'=>'campinas','uf'=>'SP','slug'=>'campinas','created_at'=>$now,'updated_at'=>$now],
   ['ibge_code'=>3136702,'name'=>'Juiz de Fora','normalized_name'=>'juiz de fora','uf'=>'MG','slug'=>'juiz-de-fora','created_at'=>$now,'updated_at'=>$now],
  ],['ibge_code'],['name','normalized_name','uf','slug','updated_at']);
  Schema::table('productions',function(Blueprint $t){if(!Schema::hasColumn('productions','city_id'))$t->unsignedBigInteger('city_id')->nullable()->index('prod_city_id_idx');if(!Schema::hasColumn('productions','address_number'))$t->string('address_number',30)->nullable();if(!Schema::hasColumn('productions','neighborhood'))$t->string('neighborhood',160)->nullable();if(!Schema::hasColumn('productions','address_complement'))$t->string('address_complement',255)->nullable();if(!Schema::hasColumn('productions','address_reference'))$t->string('address_reference',255)->nullable();if(!Schema::hasColumn('productions','formatted_address'))$t->string('formatted_address',700)->nullable();if(!Schema::hasColumn('productions','latitude'))$t->decimal('latitude',10,7)->nullable();if(!Schema::hasColumn('productions','longitude'))$t->decimal('longitude',10,7)->nullable();if(!Schema::hasColumn('productions','place_id'))$t->string('place_id',255)->nullable();if(!Schema::hasColumn('productions','google_maps_url'))$t->string('google_maps_url',2048)->nullable();if(!Schema::hasColumn('productions','location_public'))$t->boolean('location_public')->default(false);});
  Schema::table('events',function(Blueprint $t){if(!Schema::hasColumn('events','city_id'))$t->unsignedBigInteger('city_id')->nullable()->index('event_city_id_idx');if(!Schema::hasColumn('events','event_format'))$t->string('event_format',20)->default('in_person')->index('event_format_idx');if(!Schema::hasColumn('events','address_number'))$t->string('address_number',30)->nullable();if(!Schema::hasColumn('events','neighborhood'))$t->string('neighborhood',160)->nullable();if(!Schema::hasColumn('events','address_complement'))$t->string('address_complement',255)->nullable();if(!Schema::hasColumn('events','address_reference'))$t->string('address_reference',255)->nullable();if(!Schema::hasColumn('events','formatted_address'))$t->string('formatted_address',700)->nullable();if(!Schema::hasColumn('events','place_id'))$t->string('place_id',255)->nullable();if(!Schema::hasColumn('events','online_platform'))$t->string('online_platform',120)->nullable();if(!Schema::hasColumn('events','online_url'))$t->string('online_url',2048)->nullable();if(!Schema::hasColumn('events','online_instructions'))$t->text('online_instructions')->nullable();});
 }
 public function down():void{}
};
