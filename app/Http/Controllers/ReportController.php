<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    /**
     * Recebe o JSON, valida e gera o relatório PDF.
     */
    public function generatePDF(Request $request)
    {
        // Validação do JSON recebido
        $validator = Validator::make($request->all(), [
            'json_data' => 'required|json',
        ], [
            'json_data.required' => 'O campo json_data é obrigatório.',
            'json_data.json' => 'O campo json_data deve conter um JSON válido.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Decodifica o JSON enviado
        $data = json_decode($request->input('json_data'), true);

        // Verifica se os campos obrigatórios estão presentes no JSON
        $requiredFields = ['person', 'group', 'qr', 'qrPage', 'docs', 'photosBase64', 'primaryColor', 'secundaryColor'];
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            $errors = [];
            foreach ($missingFields as $field) {
                $errors[$field] = ["O campo {$field} é obrigatório."];
            }
            return response()->json([
                'status' => 'error',
                'errors' => $errors
            ], 422);
        }

        // Extrai os dados do JSON para alimentar o PDF
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
}
