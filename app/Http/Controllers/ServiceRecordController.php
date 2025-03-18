<?php

namespace App\Http\Controllers;

use App\Models\{ServiceRecord, Item, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ServiceRecordController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'app_id.required' => 'O ID do aplicativo Ã© obrigatÃ³rio.',
            'app_id.exists' => 'O ID do aplicativo deve existir na tabela de aplicaÃ§Ãµes.',
            'entity_name.required' => 'O nome da entidade Ã© obrigatÃ³rio.',
            'entity_name.string' => 'O nome da entidade deve ser uma string vÃ¡lida.',
            'entity_id.required' => 'O ID da entidade Ã© obrigatÃ³rio.',
            'entity_id.integer' => 'O ID da entidade deve ser um nÃºmero inteiro.',
            'service_ids.required' => 'A lista de serviÃ§os Ã© obrigatÃ³ria.',
            'service_ids.array' => 'Os serviÃ§os devem ser enviados como uma lista.',
            'service_ids.min' => 'A lista de serviÃ§os deve conter pelo menos um serviÃ§o.',
            'service_ids.*.integer' => 'Cada ID de serviÃ§o deve ser um nÃºmero inteiro vÃ¡lido.',
            'service_ids.*.exists' => 'Um ou mais serviÃ§os selecionados nÃ£o existem na tabela de itens.',
            'provider_id.required' => 'O prestador de serviÃ§o Ã© obrigatÃ³rio.',
            'provider_id.integer' => 'O ID do prestador de serviÃ§o deve ser um nÃºmero inteiro.',
            'client_id.required' => 'O cliente Ã© obrigatÃ³rio.',
            'client_id.integer' => 'O ID do cliente deve ser um nÃºmero inteiro.',
            'discount.numeric' => 'O valor do desconto deve ser um nÃºmero.',
            'discount.min' => 'O desconto nÃ£o pode ser negativo.',
            'payment_method.required' => 'O mÃ©todo de pagamento Ã© obrigatÃ³rio.',
            'payment_method.string' => 'O mÃ©todo de pagamento deve ser uma string vÃ¡lida.',
            'payment_method.in' => 'O mÃ©todo de pagamento selecionado nÃ£o Ã© vÃ¡lido.',
            'total_price.required' => 'O valor total do atendimento Ã© obrigatÃ³rio.',
            'total_price.numeric' => 'O valor total deve ser um nÃºmero.',
            'total_price.min' => 'O valor total deve ser no mÃ­nimo 0.',
            'status.required' => 'O status do atendimento Ã© obrigatÃ³rio.',
            'status.string' => 'O status deve ser uma string vÃ¡lida.',
            'notes.string' => 'As observaÃ§Ãµes devem ser uma string vÃ¡lida.',
        ];
    }

    // Registro de um novo atendimento
    public function store(Request $request)
    {
        try {
            Log::info('Iniciando o registro de um novo atendimento.');

            if (!Auth::check()) {
                Log::warning('UsuÃ¡rio nÃ£o autenticado tentou registrar atendimento.');
                return response()->json(['error' => 'UsuÃ¡rio nÃ£o autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('service_record_store')) {
                return response()->json(['error' => 'VocÃª nÃ£o tem permissÃ£o para registrar atendimentos.'], 403);
            }

            $validatedData = $request->validate([
                'app_id' => 'required|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
                'service_ids' => 'required|array|min:1',
                'service_ids.*' => 'integer|exists:items,id',
                'provider_id' => 'required|integer',
                'client_id' => 'required|integer',
                'discount' => 'nullable|numeric|min:0',
                'payment_method' => 'required|string|max:50|in:Pix,DÃ©bito,CrÃ©dito,Dinheiro,Fiado,Cortesia,TransferÃªncia bancÃ¡ria,Vale-refeiÃ§Ã£o,Cheque,PayPal',
                'total_price' => 'required|numeric|min:0',
                'status' => 'required|in:pending,approved,not-approved',
                'notes' => 'nullable|string',
            ], $this->getValidationMessages());

            Log::info('Dados validados para registro de atendimento.', $validatedData);

            $provider = User::find($validatedData['provider_id']);
            if (!$provider) {
                Log::warning('Prestador de serviÃ§o nÃ£o encontrado.', ['provider_id' => $validatedData['provider_id']]);
                return response()->json(['error' => 'Prestador de serviÃ§o nÃ£o encontrado.'], 404);
            }

            $client = User::find($validatedData['client_id']);
            if (!$client) {
                Log::warning('Cliente nÃ£o encontrado.', ['client_id' => $validatedData['client_id']]);
                return response()->json(['error' => 'Cliente nÃ£o encontrado.'], 404);
            }

            $serviceRecord = ServiceRecord::create([
                'app_id' => $validatedData['app_id'],
                'entity_name' => $validatedData['entity_name'],
                'entity_id' => $validatedData['entity_id'],
                'service_ids' => $validatedData['service_ids'],
                'provider_id' => $validatedData['provider_id'],
                'client_id' => $validatedData['client_id'],
                'registered_by' => $user->id,
                'discount' => $validatedData['discount'] ?? 0,
                'payment_method' => $validatedData['payment_method'],
                'total_price' => $validatedData['total_price'],
                'status' => $validatedData['status'],
                'notes' => $validatedData['notes'] ?? null,
            ]);

            Log::info('Atendimento registrado com sucesso.', ['service_record_id' => $serviceRecord->id]);

            return response()->json(['message' => 'Atendimento registrado com sucesso!', 'service_record' => $serviceRecord], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Erros de validaÃ§Ã£o ao registrar atendimento.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao registrar atendimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao registrar o atendimento.'], 500);
        }
    }

    // Lista os atendimentos do usuÃ¡rio autenticado
    public function listMy(Request $request)
    {
        try {
            Log::info('Iniciando listagem dos atendimentos do usuÃ¡rio autenticado.');

            if (!Auth::check()) {
                Log::warning('UsuÃ¡rio nÃ£o autenticado tentou acessar listMy.');
                return response()->json(['error' => 'UsuÃ¡rio nÃ£o autenticado.'], 401);
            }

            $user = Auth::user();
            $serviceRecords = ServiceRecord::where('client_id', $user->id)
                ->orderBy('created_at', 'asc')
                ->get();

            if ($serviceRecords->isEmpty()) {
                Log::warning('Nenhum atendimento encontrado para o usuÃ¡rio.', ['user_id' => $user->id]);
                return response()->json(['message' => 'Nenhum atendimento encontrado.'], 404);
            }

            $serviceRecords->each(function ($record) {
                if (!empty($record->service_ids)) {
                    $serviceIds = is_string($record->service_ids)
                        ? json_decode($record->service_ids, true)
                        : $record->service_ids;
                    if (is_array($serviceIds)) {
                        $record->service_names = Item::whereIn('id', $serviceIds)
                            ->where('category', 'ServiÃ§os')
                            ->pluck('name')
                            ->toArray();
                    } else {
                        $record->service_names = [];
                    }
                } else {
                    $record->service_names = [];
                }
            });

            return response()->json(['service_records' => $serviceRecords], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar atendimentos do usuÃ¡rio: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar os atendimentos.'], 500);
        }
    }

    // Lista os atendimentos de um cliente especÃ­fico
    public function listByClient(Request $request)
    {
        try {
            Log::info('Iniciando listagem de atendimentos por cliente e entidade.');
    
            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar listByClient.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }
    
            $user = Auth::user();
            if (!$user->hasPermission('service_record_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar atendimentos.'], 403);
            }
    
            $validatedData = $request->validate([
                'client_id'   => 'required|integer',
                'entity_name' => 'required|string',
                'entity_id'   => 'required|integer',
                'app_id'      => 'required|integer',
            ], $this->getValidationMessages());
    
            $serviceRecords = ServiceRecord::where('client_id', $validatedData['client_id'])
                ->where('entity_name', $validatedData['entity_name'])
                ->where('entity_id', $validatedData['entity_id'])
                ->where('app_id', $validatedData['app_id'])
                ->orderBy('created_at', 'asc')
                ->get();
    
            if ($serviceRecords->isEmpty()) {
                Log::warning('Nenhum atendimento encontrado para o cliente e entidade especificados.', [
                    'client_id'   => $validatedData['client_id'],
                    'entity_name' => $validatedData['entity_name'],
                    'entity_id'   => $validatedData['entity_id'],
                    'app_id'      => $validatedData['app_id'],
                ]);
                return response()->json(['message' => 'Nenhum atendimento encontrado.'], 404);
            }
    
            // Para cada atendimento, buscar os detalhes dos serviços realizados
            $serviceRecords->each(function ($record) {
                if (!empty($record->service_ids)) {
                    $serviceIds = is_string($record->service_ids)
                        ? json_decode($record->service_ids, true)
                        : $record->service_ids;
                    if (is_array($serviceIds)) {
                        // Busca os itens detalhados (id e nome) que são serviços, filtrando pelo type 'service'
                        $record->services = Item::whereIn('id', $serviceIds)
                            ->where('type', 'service')
                            ->get();
                    } else {
                        $record->services = collect([]);
                    }
                } else {
                    $record->services = collect([]);
                }
            });
    
            return response()->json(['service_records' => $serviceRecords], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao listar atendimentos: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao listar atendimentos: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar os atendimentos.'], 500);
        }
    }
    

    // Lista todos os atendimentos de uma entidade
    public function listByEntity(Request $request)
    {
        try {
            Log::info('Iniciando listagem de atendimentos por entidade.');
    
            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar listByEntity.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }
    
            $user = Auth::user();
            if (!$user->hasPermission('service_record_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar atendimentos.'], 403);
            }
    
            $validatedData = $request->validate([
                'entity_id' => 'required|integer',
                'entity_name' => 'required|string|max:255',
            ], $this->getValidationMessages());
    
            // Eager load as relações de usuário: provider, registeredBy e client
            $serviceRecords = ServiceRecord::where('entity_id', $validatedData['entity_id'])
                ->where('entity_name', $validatedData['entity_name'])
                ->orderBy('created_at', 'asc')
                ->with(['provider', 'registeredBy', 'client'])
                ->get();
    
            if ($serviceRecords->isEmpty()) {
                Log::warning('Nenhum atendimento encontrado para a entidade.', [
                    'entity_id' => $validatedData['entity_id'],
                    'entity_name' => $validatedData['entity_name'],
                ]);
                return response()->json(['message' => 'Nenhum atendimento encontrado.'], 404);
            }
    
            // Para cada atendimento, buscar os detalhes dos serviços realizados
            $serviceRecords->each(function ($record) {
                if (!empty($record->service_ids)) {
                    $serviceIds = is_string($record->service_ids)
                        ? json_decode($record->service_ids, true)
                        : $record->service_ids;
                    if (is_array($serviceIds)) {
                        // Busca os itens detalhados (id e nome) que são serviços
                        // Aqui usamos o campo 'type' com valor 'service' conforme a model Item
                        $record->services = Item::whereIn('id', $serviceIds)
                            ->where('type', 'service')
                            ->get();
                    } else {
                        $record->services = collect([]);
                    }
                } else {
                    $record->services = collect([]);
                }
            });
    
            return response()->json(['service_records' => $serviceRecords], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao listar atendimentos por entidade: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao listar atendimentos por entidade: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar os atendimentos.'], 500);
        }
    }
    


    // Lista os atendimentos do provedor
    public function listByProvider(Request $request)
    {
        try {
            Log::info('Iniciando listagem de atendimentos por provedor.');

            if (!Auth::check()) {
                Log::warning('UsuÃ¡rio nÃ£o autenticado tentou acessar listByProvider.');
                return response()->json(['error' => 'UsuÃ¡rio nÃ£o autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('service_record_list')) {
                return response()->json(['error' => 'VocÃª nÃ£o tem permissÃ£o para listar atendimentos.'], 403);
            }

            $validatedData = $request->validate([
                'provider_id' => 'required|integer|exists:users,id',
                'app_id' => 'required|integer|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
            ], $this->getValidationMessages());

            $serviceRecords = ServiceRecord::where('provider_id', $validatedData['provider_id'])
                ->where('app_id', $validatedData['app_id'])
                ->where('entity_name', $validatedData['entity_name'])
                ->where('entity_id', $validatedData['entity_id'])
                ->orderBy('created_at', 'asc')
                ->get();

            if ($serviceRecords->isEmpty()) {
                Log::warning('Nenhum atendimento encontrado para o provedor especificado.', [
                    'provider_id' => $validatedData['provider_id'],
                    'app_id' => $validatedData['app_id'],
                    'entity_name' => $validatedData['entity_name'],
                    'entity_id' => $validatedData['entity_id'],
                ]);
                return response()->json(['message' => 'Nenhum atendimento encontrado.'], 404);
            }

            return response()->json(['service_records' => $serviceRecords], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validaÃ§Ã£o ao listar atendimentos por provedor: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao listar atendimentos por provedor: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao buscar os atendimentos.'], 500);
        }
    }

    // Cancelamento de um atendimento
    public function destroy($id)
    {
        try {
            Log::info('Iniciando a inativaÃ§Ã£o do atendimento.', ['service_record_id' => $id]);

            if (!Auth::check()) {
                Log::warning('UsuÃ¡rio nÃ£o autenticado tentou inativar o atendimento.');
                return response()->json(['error' => 'UsuÃ¡rio nÃ£o autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('service_record_destroy') && !$user->hasPermission('service_record_destroy_all')) {
                return response()->json(['error' => 'VocÃª nÃ£o tem permissÃ£o para inativar atendimentos.'], 403);
            }

            $serviceRecord = ServiceRecord::find($id);
            if (!$serviceRecord) {
                Log::warning('Atendimento nÃ£o encontrado.', ['service_record_id' => $id]);
                return response()->json(['error' => 'Atendimento nÃ£o encontrado.'], 404);
            }

            // Atualiza o status para "cancelled" em vez de deletar o registro
            $serviceRecord->status = 'not-approved';
            $serviceRecord->save();

            Log::info('Atendimento cancelado com sucesso.', ['service_record_id' => $id]);
            return response()->json(['message' => 'Atendimento inativado com sucesso!'], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao cancelar atendimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cancelar o atendimento.'], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            Log::info('Iniciando atualização do status do atendimento.', ['service_record_id' => $id]);

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou atualizar o status do atendimento.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('service_record_update_status')) {
                return response()->json(['error' => 'Você não tem permissão para atualizar o status do atendimento.'], 403);
            }

            // Validação do novo status
            $validatedData = $request->validate([
                'status' => 'required|string|max:50'
            ], $this->getValidationMessages());

            $allowedStatuses = ['pending', 'approved', 'not-approved'];
            $newStatus = strtolower($validatedData['status']);

            if (!in_array($newStatus, $allowedStatuses)) {
                return response()->json(['error' => 'Status inválido.'], 422);
            }

            $serviceRecord = ServiceRecord::find($id);
            if (!$serviceRecord) {
                Log::warning('Atendimento não encontrado.', ['service_record_id' => $id]);
                return response()->json(['error' => 'Atendimento não encontrado.'], 404);
            }

            $serviceRecord->status = $newStatus;
            $serviceRecord->save();

            Log::info('Status do atendimento atualizado com sucesso.', [
                'service_record_id' => $id,
                'status' => $newStatus
            ]);

            return response()->json([
                'message' => 'Status do atendimento atualizado com sucesso!',
                'service_record' => $serviceRecord
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao atualizar o status do atendimento: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar o status do atendimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o status do atendimento.'], 500);
        }
    }

}
