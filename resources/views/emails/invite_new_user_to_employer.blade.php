<!-- resources/views/emails/invite_new_user_to_employer.blade.php -->
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Você foi adicionado como colaborador</title>
</head>
<body style="margin:0;padding:0;background-color:#0B1F30;font-family:'Segoe UI',sans-serif;color:#FFFFFF;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0B1F30;padding:30px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0"
               style="background-color:#132A3A;border:1px solid #00BFFF;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.3);padding:30px;">
          
          <!-- Logo -->
          <tr>
            <td align="center" style="padding-bottom:20px;">
              <img src="https://petertecnet.com.br/logo.png"
                   alt="Logo Peter Tecnet"
                   width="120"
                   style="display:block;border:none;outline:none;text-decoration:none;"/>
            </td>
          </tr>

          <!-- Greeting -->
          <tr>
            <td style="color:#FFFFFF;font-size:16px;line-height:1.5;">
              <p style="margin:0 0 15px;">
                Olá <strong style="color:#00BFFF;">{{ $userName }}</strong>,
              </p>

              <p style="margin:0 0 15px;">
                Você foi adicionado(a) como colaborador(a) no estabelecimento
                <strong style="color:#00BFFF;">{{ $establishmentName }}</strong>
                com a função de
                <strong style="color:#00BFFF;">{{ $role }}</strong>.
              </p>

              <p style="margin:0 0 15px;">
                Agora você possui acesso às funcionalidades de gerenciamento
                e pode ajudar nas operações diárias. Acesse o painel para
                visualizar suas permissões e começar a colaborar.
              </p>

              <p style="margin:0 0 15px;">
                Se tiver alguma dúvida, entre em contato com o proprietário
                do estabelecimento.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td align="center" style="padding-top:20px;color:#FFFFFF;font-size:12px;line-height:1.4;">
              © {{ date('Y') }} Todos os direitos reservados.
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
