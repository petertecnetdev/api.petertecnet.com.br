<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $nexus = DB::table('applications')
            ->where('slug', 'nexus')
            ->orWhereRaw('LOWER(name) = ?', ['nexus'])
            ->first();

        if (! $nexus) {
            throw new RuntimeException('Aplicação Nexus não encontrada para carga do catálogo do Cirilo Ferragista.');
        }

        $cirilo = DB::table('establishments')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%cirilo%'])
                    ->orWhereRaw("LOWER(COALESCE(fantasy, '')) LIKE ?", ['%cirilo%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%cirilo%']);
            })
            ->orderByRaw("CASE WHEN LOWER(name) LIKE '%cirilo%ferrag%' THEN 1 WHEN LOWER(COALESCE(fantasy, '')) LIKE '%cirilo%ferrag%' THEN 2 WHEN LOWER(slug) LIKE '%cirilo%ferrag%' THEN 3 ELSE 4 END")
            ->first();

        if (! $cirilo) {
            throw new RuntimeException('Estabelecimento Cirilo Ferragista não encontrado para carga do catálogo.');
        }

        $products = [
            ['Lotus Trena de Fibra Aberta 30 m',49.00,'Ferramentas','Medição','Lotus','Trena profissional de fibra aberta com alcance de 30 metros, indicada para medições em obras, terrenos e serviços profissionais.'],
            ['Esmalte Sintético Preto 3,6 L',110.00,'Tintas e Pintura','Esmalte Sintético',null,'Esmalte sintético preto em embalagem de 3,6 litros, indicado para pintura, renovação e acabamento de superfícies compatíveis.'],
            ['Esmalte Sintético Neve 3,6 L',106.00,'Tintas e Pintura','Esmalte Sintético',null,'Esmalte sintético na cor neve em embalagem de 3,6 litros, indicado para pintura, renovação e acabamento.'],
            ['Tekbond Silicone Acético Transparente',15.00,'Colas e Adesivos','Silicone','Tekbond','Silicone acético transparente indicado para vedação, acabamento e pequenos reparos em aplicações compatíveis.'],
            ['Standard Semibrilho Neve 3,6 L',75.00,'Tintas e Pintura','Tinta Semibrilho','Standard','Tinta Standard semibrilho na cor neve, embalagem de 3,6 litros, indicada para pintura e renovação de ambientes.'],
            ['Parafuso para Madeira 5 x 25 mm',4.00,'Ferragens e Fixação','Parafusos','Tools','Parafuso 5 x 25 mm indicado para fixações em madeira, montagem, manutenção e pequenos reparos.'],
            ['Lâmpada LED Xibra 9 W',5.00,'Elétrica e Iluminação','Lâmpadas LED','Xibra','Lâmpada LED Xibra de 9 watts para iluminação residencial e comercial.'],
            ['Lâmpada LED Xibra 12 W',6.00,'Elétrica e Iluminação','Lâmpadas LED','Xibra','Lâmpada LED Xibra de 12 watts para iluminação residencial e comercial.'],
            ['Lâmpada LED Xibra 20 W',10.00,'Elétrica e Iluminação','Lâmpadas LED','Xibra','Lâmpada LED Xibra de 20 watts indicada para ambientes que exigem maior intensidade luminosa.'],
            ['Lâmpada LED Xibra 40 W',18.00,'Elétrica e Iluminação','Lâmpadas LED','Xibra','Lâmpada LED Xibra de 40 watts indicada para iluminação de ambientes amplos.'],
            ['Lâmpada LED Xibra 50 W',28.00,'Elétrica e Iluminação','Lâmpadas LED','Xibra','Lâmpada LED Xibra de 50 watts indicada para áreas maiores, estabelecimentos, garagens e ambientes diversos.'],
            ['Saco para Lixo 30 Litros',15.00,'Limpeza','Sacos para Lixo',null,'Saco para lixo com capacidade de 30 litros, indicado para uso doméstico, comercial e limpeza em geral.'],
            ['Saco para Lixo 50 Litros - 30 Unidades',15.00,'Limpeza','Sacos para Lixo',null,'Pacote com 30 sacos para lixo de 50 litros, indicado para residências, estabelecimentos comerciais e limpeza em geral.'],
            ['Corda 8 mm - Metro',2.00,'Ferragens','Cordas',null,'Corda de 8 mm vendida por metro, indicada para amarrações, manutenção, construção e aplicações diversas.'],
            ['Corda 12 mm - Metro',4.00,'Ferragens','Cordas',null,'Corda de 12 mm vendida por metro para amarrações e aplicações que necessitam de maior espessura.'],
            ['Corda 10 mm - Metro',6.00,'Ferragens','Cordas',null,'Corda de 10 mm vendida por metro para amarrações, construção, manutenção e aplicações diversas.'],
            ['Corda 6 mm - Metro',1.80,'Ferragens','Cordas',null,'Corda de 6 mm vendida por metro, indicada para amarrações leves e aplicações diversas.'],
            ['Corda 5 mm - Metro',1.50,'Ferragens','Cordas',null,'Corda de 5 mm vendida por metro para pequenas amarrações, manutenção e usos diversos.'],
            ['Corda 4 mm - Metro',1.30,'Ferragens','Cordas',null,'Corda de 4 mm vendida por metro para pequenas amarrações e aplicações gerais.'],
            ['Chuveiro Lorenzetti Bella Ducha 220 V 690 W',102.00,'Hidráulica','Chuveiros','Lorenzetti','Chuveiro Lorenzetti Bella Ducha para instalação elétrica 220 V. Potência cadastrada conforme informação fornecida: 690 W.'],
            ['Cola Plástica Série Especial Cinza',15.00,'Colas e Adesivos','Cola Plástica',null,'Cola plástica série especial na cor cinza para reparos, preenchimentos e aplicações compatíveis.'],
            ['Adesivo Amazonas',20.00,'Colas e Adesivos','Adesivos','Amazonas','Adesivo Amazonas para aplicações gerais de colagem e reparo conforme especificações do fabricante.'],
            ['Unaflex Adesivo 750 g',40.00,'Colas e Adesivos','Adesivos','Unaflex','Adesivo Unaflex em embalagem de 750 g para serviços de colagem e aplicações compatíveis.'],
            ['Cola Condor - Tubo',23.90,'Colas e Adesivos','Colas','Condor','Cola Condor em tubo para pequenos reparos, colagens e aplicações gerais de manutenção.'],
            ['DRYKO Primer Acqua - Emulsão Asfáltica Base Água',19.00,'Impermeabilização','Primer Asfáltico','DRYKO','Primer à base de água para preparação de superfícies em sistemas de impermeabilização e aplicações com produtos asfálticos.'],
            ['Anjo Massa Corrida',18.50,'Tintas e Pintura','Massa Corrida','Anjo','Massa corrida indicada para preparação, regularização e acabamento de superfícies antes da pintura.'],
            ['Pá de Pedreiro',47.00,'Ferramentas','Construção Civil',null,'Pá de pedreiro indicada para serviços de construção civil, preparo e movimentação de materiais.'],
            ['Pá Tramontina com Cabo de Madeira',35.00,'Ferramentas','Pás','Tramontina','Pá Tramontina com cabo de madeira indicada para construção, jardinagem e movimentação de materiais.'],
            ['Inova Mix Metais',28.00,'Hidráulica','Torneiras e Acessórios','Inova Mix','Componente para torneira da linha Inova Mix Metais para instalação, reposição ou manutenção hidráulica conforme compatibilidade.'],
            ['Torneira de Cozinha Branca de Parede com Bica Móvel',25.00,'Hidráulica','Torneiras',null,'Torneira branca para cozinha com instalação em parede e bica móvel.'],
            ['Torneira de Jardim de Parede Cromada',45.00,'Hidráulica','Torneiras',null,'Torneira cromada de parede indicada para jardim, áreas externas e pontos de água.'],
            ['Torneira para Máquina de Lavar',30.00,'Hidráulica','Torneiras',null,'Torneira indicada para ligação de máquina de lavar roupas em pontos hidráulicos compatíveis.'],
            ['Conjunto de Fixação para Assento Sanitário',12.00,'Hidráulica','Acessórios Sanitários',null,'Conjunto de fixação para instalação ou reposição de assentos sanitários.'],
            ['Cabo de Áudio e Vídeo MXT',20.00,'Elétrica e Eletrônicos','Cabos','MXT','Cabo de áudio e vídeo MXT para conexão de equipamentos compatíveis.'],
            ['Organizador de Cabos',7.00,'Elétrica e Eletrônicos','Organização de Cabos',null,'Organizador para manter cabos agrupados, protegidos e melhor distribuídos.'],
            ['Cabo de Rede',12.00,'Informática e Redes','Cabos de Rede',null,'Cabo de rede para conexão de computadores, roteadores e equipamentos compatíveis.'],
            ['Disco Brasil para Ferramenta de Corte',15.00,'Ferramentas','Discos e Acessórios','Brasil','Disco para uso em ferramenta elétrica compatível, como esmerilhadeira ou equipamento de corte. Verifique medidas e aplicação antes do uso.'],
        ];

        foreach ($products as [$name, $price, $category, $subcategory, $brand, $description]) {
            $slugBase = Str::slug($name) ?: 'item';
            $existing = DB::table('items')
                ->where('app_id', $nexus->id)
                ->where('entity_id', $cirilo->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            $payload = [
                'user_id' => $cirilo->user_id,
                'app_id' => $nexus->id,
                'name' => $name,
                'type' => 'product',
                'description' => $description,
                'price' => $price,
                'stock' => 0,
                'status' => true,
                'limited_by_user' => false,
                'category' => $category,
                'subcategory' => $subcategory,
                'brand' => $brand,
                'entity_id' => $cirilo->id,
                'entity_name' => 'establishment',
                'tags' => json_encode(array_values(array_filter([$category, $subcategory, $brand])), JSON_UNESCAPED_UNICODE),
                'created_by' => $cirilo->user_id,
                'updated_by' => $cirilo->user_id,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('items')->where('id', $existing->id)->update($payload);
                continue;
            }

            $slug = $slugBase;
            $suffix = 1;
            while (DB::table('items')->where('app_id', $nexus->id)->where('slug', $slug)->exists()) {
                $slug = $slugBase.'-'.$suffix++;
            }

            $payload['slug'] = $slug;
            $payload['created_at'] = now();
            DB::table('items')->insert($payload);
        }
    }

    public function down(): void
    {
        // Data migration intentionally does not delete catalog data on rollback.
    }
};
