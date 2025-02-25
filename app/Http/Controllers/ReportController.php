<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    /**
     * Recebe o JSON dentro do campo "json_data", valida e gera o relatório PDF.
     */
    public function generatePDF(Request $request)
    {
        // Validação do JSON recebido
        $validatedRequest = $request->validate([
            'json_data' => 'required|array',
        ], $this->getValidationMessages());

        // Captura os dados dentro do campo "json_data"
        $data = $validatedRequest['json_data'];

        // Validação dos dados internos do JSON
        $validatedData = \Validator::make($data, [
            'person' => 'required|array',
            'group' => 'required|array',
            'qr' => 'required|string',
            'qrPage' => 'required|string|url',
            'docs' => 'required|array',
            'docs.documentFront.photo' => 'required|string|url',
            'docs.documentBack.photo' => 'required|string|url',
            'photosBase64' => 'required|array',
            'photosBase64.documentFront' => 'required|string',
            'photosBase64.documentBack' => 'required|string',
            'photosBase64.faceMatchPerson' => 'nullable|string',
            'photosBase64.faceMatchDocument' => 'nullable|string',
            'photosBase64.qrCode' => 'required|string',
            'primaryColor' => 'required|string',
            'secundaryColor' => 'required|string',
            'reportType' => 'required|string|in:Resumido,Completo',
            'verifications' => 'required|array',
        ], $this->getValidationMessages());

        if ($validatedData->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validatedData->errors()
            ], 422);
        }

        // Dados validados
        $person         = $data['person'];
        $group          = $data['group'];
        $qr             = $data['qr'];
        $qrPage         = $data['qrPage'];
        $docs           = $data['docs'];
        $photosBase64   = $data['photosBase64'];
        $primaryColor   = $data['primaryColor'];
        $secundaryColor = $data['secundaryColor'];

        // Gera o PDF utilizando a view "reports.criminal_record"
        $pdf = Pdf::loadView('reports.criminal_record', compact(
            'person',
            'group',
            'qr',
            'qrPage',
            'docs',
            'photosBase64',
            'primaryColor',
            'secundaryColor'
        ));

        // Retorna o PDF como resposta para download
        return $pdf->stream('relatorio_antecedentes_criminais.pdf');
    }

    /**
     * Retorna as mensagens de erro personalizadas para a validação.
     */
    protected function getValidationMessages()
    {
        return [
            'json_data.required' => 'O campo "json_data" é obrigatório e deve conter o JSON válido.',
            'json_data.array' => 'O campo "json_data" deve ser um objeto JSON.',

            'person.required' => 'O campo "person" é obrigatório.',
            'person.array' => 'O campo "person" deve ser um objeto JSON.',
            'group.required' => 'O campo "group" é obrigatório.',
            'group.array' => 'O campo "group" deve ser um objeto JSON.',
            'qr.required' => 'O campo "qr" é obrigatório.',
            'qr.string' => 'O campo "qr" deve ser uma string.',
            'qrPage.required' => 'O campo "qrPage" é obrigatório.',
            'qrPage.string' => 'O campo "qrPage" deve ser uma string.',
            'qrPage.url' => 'O campo "qrPage" deve ser uma URL válida.',
            'docs.required' => 'O campo "docs" é obrigatório.',
            'docs.array' => 'O campo "docs" deve ser um objeto JSON.',
            'docs.documentFront.photo.required' => 'O campo "documentFront.photo" é obrigatório.',
            'docs.documentFront.photo.string' => 'O campo "documentFront.photo" deve ser uma string.',
            'docs.documentFront.photo.url' => 'O campo "documentFront.photo" deve ser uma URL válida.',
            'docs.documentBack.photo.required' => 'O campo "documentBack.photo" é obrigatório.',
            'docs.documentBack.photo.string' => 'O campo "documentBack.photo" deve ser uma string.',
            'docs.documentBack.photo.url' => 'O campo "documentBack.photo" deve ser uma URL válida.',
            'photosBase64.required' => 'O campo "photosBase64" é obrigatório.',
            'photosBase64.array' => 'O campo "photosBase64" deve ser um objeto JSON.',
            'photosBase64.documentFront.required' => 'O campo "photosBase64.documentFront" é obrigatório.',
            'photosBase64.documentFront.string' => 'O campo "photosBase64.documentFront" deve ser uma string.',
            'photosBase64.documentBack.required' => 'O campo "photosBase64.documentBack" é obrigatório.',
            'photosBase64.documentBack.string' => 'O campo "photosBase64.documentBack" deve ser uma string.',
            'photosBase64.qrCode.required' => 'O campo "photosBase64.qrCode" é obrigatório.',
            'photosBase64.qrCode.string' => 'O campo "photosBase64.qrCode" deve ser uma string.',
            'primaryColor.required' => 'O campo "primaryColor" é obrigatório.',
            'primaryColor.string' => 'O campo "primaryColor" deve ser uma string.',
            'secundaryColor.required' => 'O campo "secundaryColor" é obrigatório.',
            'secundaryColor.string' => 'O campo "secundaryColor" deve ser uma string.',
            'reportType.required' => 'O campo "reportType" é obrigatório.',
            'reportType.string' => 'O campo "reportType" deve ser uma string.',
            'reportType.in' => 'O campo "reportType" deve ser "Resumido" ou "Completo".',
            'verifications.required' => 'O campo "verifications" é obrigatório.',
            'verifications.array' => 'O campo "verifications" deve ser um objeto JSON.',
        ];
    }
}
