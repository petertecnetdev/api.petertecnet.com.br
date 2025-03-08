<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email de Associação à Barbearia - Rasoio</title>
  <style>
    /* CSS conforme fornecido */
    body {
      background-image: url('images/background-2.png'); /* Imagem de fundo */
      background-size: cover; /* Cobre toda a área do body */
      background-repeat: no-repeat; /* Não repete a imagem de fundo */
      background-position: center center; /* Centraliza a imagem de fundo */
      background-attachment: fixed; /* A imagem de fundo permanece fixa durante a rolagem */
      color: white; /* Cor do texto */
      margin: 0; /* Remove a margem padrão */
    }
    .labellight{
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(17, 17, 17, 0.8));
      border-radius: 12px;
      font-size: 15px;
      padding: 5px;
    }
    .labeltitle {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(84, 133, 141, 0.8));
      padding: 0.3vw;
      color: white;
      border-radius: 2vw;
      margin-top: 1vw;
      box-shadow: 0.1vw 0.1vw 1vw 0.2vw rgba(255, 255, 255, 0.8);
      text-align: center;
      opacity: 0.8;
      border: 1px solid #FFFFFF;
    }
    .labelinfo{
      background: linear-gradient(135deg, rgb(14, 3, 50), rgba(84, 133, 141, 0.8));
      padding: 5px;
      color: white;
      border-radius: 6px;
      margin-top: 20px;
      box-shadow: 0.1vw 0.1vw 1vw 0.2vw rgba(29, 156, 206, 0.8);
      text-align: center;
      opacity: 0.9;
      font-size: 13px;
      border: 1px solid #FFFFFF;
    }
    .labeltitle2 {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(4, 67, 77, 0.8));
      padding: 0.3vw;
      color: rgb(10, 24, 101);
      border-radius: 2vw;
      margin-top: 1vw;
      box-shadow: 0.1vw 0.1vw 1vw 0.2vw rgba(255, 255, 255, 0.8);
      text-align: center;
      opacity: 0.8;
      font-size: 12px;
      border: 3px solid #FFFFFF;
    }
    /* CARDS */
    .card {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(84, 133, 141, 0.8));
      color: #f0f0f0; /* Texto claro */
      padding: 20px;
      border-radius: 20px;
      margin: 20px 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border: 1px solid #FFFFFF;
    }
    .card-1 {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(84, 133, 141, 0.8));
      color: #f0f0f0;
      padding: 20px;
      border-radius: 20px;
      margin: 20px 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border: 1px solid #FFFFFF;
    }
    .card:hover {
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.4);
    }
    .card h3 {
      color: rgb(84, 133, 141); /* Título com cor dominante */
    }
    .card p {
      color: #f0f0f0; /* Texto claro */
    }
    /* Navbar Principal */
    .navbar {
      background: linear-gradient(135deg, rgba(0, 0, 0, 0.9), rgba(84, 133, 141, 0.8));
      border-radius: 40px;
      margin: 10px;
      margin-top: 10px;
      padding: 20px 30px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
      backdrop-filter: blur(10px);
      transition: background 0.3s ease;
    }
    .navbar .nav-link {
      color: #e0e0e0 !important;
      font-family: Arial, sans-serif;
      font-weight: 500;
      transition: color 0.3s ease, transform 0.3s ease;
    }
    .navbar .nav-link:hover {
      color: #ffffff !important;
      transform: translateY(-2px);
    }
    .navbar .navbar-brand {
      color: #ffffff !important;
      font-family: 'Orbitron', sans-serif;
      font-size: 1.5rem;
    }
    .navbar-toggler-icon {
      background-color: transparent;
      border: 1px solid white;
      border-radius: 5px;
    }
    .navbar-toggler {
      border: none;
      background-color: transparent;
      outline: none;
      transition: transform 0.3s ease, opacity 0.3s ease;
    }
    .navbar-toggler:hover {
      transform: scale(1.1);
      opacity: 0.8;
    }
    .notifications-dropdown .dropdown-menu {
      margin-right: -90px;
      left: -365px;
      right: 20px;
      width: auto;
      max-width: 505px;
      color: white;
      background-color: rgba(5, 33, 92);
    }
    @media (max-width: 576px) {
      .notifications-dropdown .dropdown-menu {
        left: 0;
        margin-right: 0;
        right: 0;
        width: 100%;
        max-width: none;
        border-radius: 0;
      }
    }
    @media (max-width: 576px) {
      .navbar-brand img {
        width: 40px;
        height: 40px;
      }
      .navbar-nav .nav-link {
        padding: 0.5rem 0.5rem;
      }
    }
    @media (min-width: 768px) {
      .notifications-dropdown .dropdown-menu {
        left: -365px;
        margin-right: -90px;
        max-width: 505px;
      }
    }
    .user-menu .dropdown-toggle {
      color: #ffffff !important;
      font-weight: bold;
      transition: color 0.3s ease;
    }
    .user-menu .dropdown-toggle:hover {
      color: #ffcc00 !important;
    }
    .dropdown-menu {
      background-color: rgba(30, 30, 30, 0.9);
      border-radius: 10px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    }
    .dropdown-item {
      color: #e0e0e0;
      transition: background 0.3s ease;
    }
    .dropdown-item:hover {
      background-color: rgba(255, 204, 0, 0.2);
      color: #ffffff;
    }
    @keyframes blink {
      0% {
        border: 0.1vw solid rgb(255, 0, 0);
      }
      50% {
        border: 0.1vw solid rgb(255, 255, 255);
      }
      100% {
        border: 0.1vw solid rgb(255, 0, 0);
      }
    }
    .logo{
      width: "50px";
      height: "50px";
    }
    .img-logo-barbershop {
      position: relative;
      width: 25%;
      max-width: 15vw;
      height: auto;
      padding: 1.5vw;
      box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
    }
    .img-logo-barbershop-show {
      position: relative;
      margin-left: 80%;
      margin-top: -50%;
      width: 30%;
      height: auto;
      padding: 7px;
      border: 1px solid white;
      box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
    }
    .img-logo-barbershop-dashboard {
      border:1px solid white;
      width: 50%;
      box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
    }
    .img-avatar-owner{
      position: relative;
      width: 50px;
      height: auto;
      border-radius: 50%;
      max-width: 15vw;
    }
    .img-avatar-user{
      position: relative;
      margin-left: 80%;
      margin-top: -50%;
      width: 100px;
      max-width: 15vw;
      height: 100px;
      padding: 1px;
      border: 1px solid white;
      box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
    }
    .img-avatar-barber{
      width: 50px; 
      height: 50px; 
      padding: 7px;
      margin-top: -50%;
      border: 1px solid white;
    }
    .img-logo-user{
      position: relative;
      margin-left: 80%;
      margin-top: -50%;
      width: 50px; 
      height: 50px; 
      border-radius: 50%; 
      max-width: 15vw;
      padding: 7px;
      border: 0.2vw solid white;
    }
    .main-content{
      padding: 10px;
      margin: 10px;
    }
    .img-logo-barber-show {
      position: relative;
      margin-left: 80%;
      margin-top: -50%;
      width: 30%;
      max-width: 15vw;
      height: auto;
      padding: 7px;
      border: 0.2vw solid white;
      box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
    }
    .img-background-barbershop {
      border: 0.1vw solid white;
      backdrop-filter: blur(1px);
      border: 1px solid white;
    }
    .card-barbershop {
      background-color: rgba(5, 33, 92, 0.8);
      color: rgb(255, 255, 255);
      padding: 1px;
      backdrop-filter: blur(0.1vw);
      font-size: 1.5vw;
      border-radius: 2vw;
      margin: 1.5vw;
      border: 0.2vw solid rgb(255, 255, 255);
    }
    .card-barbershop-show {
      background-color: rgba(5, 33, 92, 0.8);
      color: rgb(255, 255, 255);
      padding: 1px;
      backdrop-filter: blur(1px);
      border-radius: 15px;
      border: 1px solid rgb(255, 255, 255);
    }
    .card-barber-show {
      background-color: rgba(5, 33, 92, 0.8);
      color: rgb(255, 255, 255);
      padding: 10px;
      backdrop-filter: blur(0.1vw);
      border-radius: 2vw;
      margin: 1.5vw;
      border: 0.2vw solid rgb(255, 255, 255);
    }
    @keyframes spinAndBoom {
      0% {
        transform: rotate(0deg) scale(1);
        box-shadow: 0.5vw 0.5vw 1vw rgba(0, 0, 0, 0.25), -0.5vw -0.5vw 1vw rgba(255, 255, 255, 0.1);
      }
      50% {
        transform: rotate(180deg) scale(1.05);
        box-shadow: 1vw 1vw 1.5vw rgba(0, 0, 0, 0.35), -1vw -1vw 1.5vw rgba(255, 255, 255, 0.2);
      }
    }
    .card-user {
      background-image: linear-gradient(to right, #092e35, #010825);
      color: rgb(199, 232, 241);
      padding: 1vw;
      backdrop-filter: blur(0.1vw);
      font-size: 1.5vw;
      border-radius: 2vw;
      margin: 1.5vw;
      border: 0.1vw solid rgb(255, 255, 255);
    }
    .avatar2{
      position: relative;
      margin-left: 80%;
      margin-top: -50%;
      width: 30%;
      max-width: 15vw;
      height: auto;
      padding: 7px;
      border: 0.2vw solid white;
      box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
    }
    .auth-dropdown {
      margin-right: 2.5vw;
      color: white;
    }
    .auth-dropdown .dropdown-menu {
      left: -50%;
      border: none;
      box-shadow: 0 0 1vw rgba(0, 0, 0, 0.1);
      background-image: linear-gradient(to right, #96e5f3, #f0f0f0);
    }
    @keyframes fadeIn {
      0% {
        opacity: 0.5;
      }
      50% {
        opacity: 0.8;
      }
      100% {
        opacity: 1;
      }
    }
    .modal {
      background-color: rgba(7, 9, 133, 0.1);
      animation: fadeIn 1s ease;
    }
    .modal.fade {
      animation: fadeIn 1s ease;
    }
    .modal-content {
      border-radius: 2vw;
      border: 1px solid white;
      color: white;
      background-color: rgba(7, 9, 133, 0.5);
    }
    .seguiments {
      color: white;
      border: 1px white;
      padding: 5px;
      margin: 0 auto;
      font-size: 13px;
    }
    .seguiment-card{
      border-radius: 80px;
      background-color: rgb(10, 36, 53);
      text-align: center;
      padding: 1px;
      font-size: 13px;
      margin: 0 auto;
    }
    .background-image {
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      filter: blur(20px);
      width: 100%;
      height: 100%;
      position: absolute;
      top: 0;
      left: 0;
      z-index: -1;
    }
    .card-barbershop {
      position: relative;
      overflow: hidden;
    }
    .card-event-view {
      position: relative;
      overflow: hidden;
    }
    .background-image-event {
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      filter: blur(2px);
      width: 100%;
      height: 100%;
      position: absolute;
      top: 0;
      left: 0;
      z-index: -1;
    }
    .img-event {
      width: 100%;
      height: auto;
      object-fit: cover;
    }
    .event-barbershop-logo{
      border-radius: 50px;
      width: 20%;
      height: 10%;
      padding: 5px;
      border: 1px solid white;
      box-shadow: 0.1vw 0.1vw 1vw 0.2vw rgba(255, 255, 255, 0.8);
      margin: 2%;
    }
    @media (max-width: 767px) {
      body {
        background-image: url('images/background.png');
        background-size: cover;
        background-repeat: no-repeat;
        background-position: center center;
        background-attachment: fixed;
        color: white;
      }
      .card {
        background-color: rgba(7, 9, 133, 0.2);
        color: white;
        padding: 3px;
        border-radius: 3px;
        border: 1px solid rgb(10, 42, 223);
        backdrop-filter: blur(1px);
        margin: 1px;
      }
      .table {
        background-color: rgba(255, 255, 255, 0.1);
        color: white;
        border: 1px solid rgb(10, 42, 223);
        border-radius: 3px;
        overflow: hidden;
      }
      .table thead {
        background-color: rgba(10, 42, 223, 0.5);
        text-transform: uppercase;
        font-weight: bold;
      }
      .table thead th {
        color: white;
        padding: 10px;
        border-bottom: 2px solid rgb(10, 42, 223);
        text-align: center;
      }
      .table tbody tr {
        transition: background-color 0.3s;
      }
      .table tbody tr:hover {
        background-color: rgba(10, 42, 223, 0.2);
      }
      .table tbody td {
        padding: 8px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        text-align: center;
      }
      .table tbody td img {
        border: 2px solid rgba(255, 255, 255, 0.5);
        border-radius: 50%;
        width: 50px;
        height: 50px;
        object-fit: cover;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
      }
      .img-logo-barbershop {
        position: relative;
        width: 30%;
        max-width: 15vw;
        height: auto;
        padding: 1.5vw;
        border: 0.2vw solid white;
        box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
      }
      .card-barbershop {
        background-color: rgba(5, 33, 92, 0.8);
        color: rgb(255, 255, 255);
        padding: 10vw;
        backdrop-filter: blur(2vw);
        font-size: 3vw;
        border-radius: 4vw;
        margin: 2.5vw;
        border: 0.5vw solid rgb(255, 255, 255);
      }
      .img-logo-barbershop2 {
        position: relative;
        margin-left: 35% !important;
        margin-top: 2% !important;
        width: 30%;
        max-width: 15vw;
        height: auto;
        padding: 1.5vw;
        border: 0.2vw solid white;
        box-shadow: 1vw 0.1vw 1.5vw 0.2vw rgba(255, 255, 255, 0.9);
      }
      .card-user {
        background-image: linear-gradient(to right, #092e35, #010825);
        color: rgb(199, 232, 241);
        padding: 3vw;
        backdrop-filter: blur(1vw);
        font-size: 3vw;
        border-radius: 4vw;
        margin: 2.5vw;
        border: 0.5vw solid rgb(255, 255, 255);
      }
      .auth-dropdown {
        margin-right: 5vw;
        color: white;
      }
      .auth-dropdown .dropdown-menu {
        left: -50%;
        border: none;
        box-shadow: 0 0 2vw rgba(0, 0, 0, 0.1);
        background-image: linear-gradient(to right, #96e5f3, #f0f0f0);
      }
    }
    @media (min-width: 768px) and (max-width: 1023px) {
      body {
        /* Estilos específicos para telas entre 768px e 1023px */
      }
    }
    .sticky-summary {
      position: -webkit-sticky;
      position: sticky;
      top: 0;
      z-index: 1;
      background: rgba(255, 255, 255, 0.8);
      border: #010825 10px solid;
      border-radius: 8px;
      overflow: hidden;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: center;
      top: 130px;
    }
    .sticky-summary::before {
      position: sticky;
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-size: cover;
      background-position: center;
      filter: blur(58px);
      z-index: -1;
    }
    .custom-swal {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgb(85 134 141));
      color: #f0f0f0;
      padding: 20px;
      border-radius: 20px;
      margin: 20px 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border: 1px solid #FFFFFF;
    }
    .custom-swal-title {
      color: white;
    }
    .custom-swal-text {
      color: white;
    }
    .custom-swal .swal2-confirm {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(84, 133, 141, 0.8));
      color: #f0f0f0;
      padding: 10px 20px;
      border-radius: 10px;
      border: 1px solid #FFFFFF;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .custom-swal .swal2-confirm:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
    }
    .custom-swal .swal2-cancel {
      background: transparent;
      color: #f0f0f0;
      border: 1px solid #f0f0f0;
      border-radius: 10px;
      padding: 10px 20px;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .custom-swal .swal2-cancel:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
    }
    button {
      background: linear-gradient(135deg, rgba(0, 0, 0, 1), rgba(84, 133, 141, 0.8));
      color: #f0f0f0;
      padding: 10px 20px;
      border-radius: 40px;
      border: 1px solid #FFFFFF;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      cursor: pointer;
    }
    button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
    }
    button:focus {
      outline: none;
      box-shadow: 0 0 0 3px rgba(84, 133, 141, 0.5);
    }
    .auth-link {
      color: #f3ebeb;
      font-weight: bold;
      text-decoration: underline;
    }
    .auth-link:hover {
      color: #ffffff;
      text-decoration: none;
    }
    .item-image {
      width: 140px;
      height: 140px;
      object-fit: cover;
      border-radius: 16px;
      border: 2px solid transparent;
      transition: all 0.3s ease-in-out;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
      position: relative;
      overflow: hidden;
    }
    .item-image:hover {
      transform: scale(1.08);
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
      border-color: #ff7f50;
    }
    .item-image::before {
      content: "";
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.3) 10%, transparent 60%);
      opacity: 0;
      transition: opacity 0.3s ease-in-out;
    }
    .item-image:hover::before {
      opacity: 1;
    }
    
    /* Estilos específicos para o email utilizando as cores solicitadas:
       Preto (principal), Branco (secundário) e Amarelo (alternativo) */
    .email-wrapper {
      padding: 30px 0;
    }
    .email-container {
      max-width: 600px;
      margin: auto;
      background-color: #000; /* Fundo preto */
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    }
    .header {
      background-color: #000; /* Preto */
      padding: 20px;
      text-align: center;
    }
    .header h1 {
      color: #fff; /* Branco */
      margin: 0;
      font-size: 24px;
    }
    .header img {
      width: 60px;
      margin-bottom: 10px;
    }
    .content {
      padding: 30px 20px;
      text-align: center;
    }
    .content h2 {
      font-size: 22px;
      margin-bottom: 15px;
      color: #fff;
    }
    .content p {
      font-size: 16px;
      line-height: 1.6;
      margin-bottom: 15px;
      color: #fff;
    }
    .btn-acessar {
      display: inline-block;
      text-decoration: none;
      background-color: #ffd700; /* Amarelo alternativo */
      color: #000; /* Preto para contraste */
      padding: 12px 30px;
      border-radius: 30px;
      font-weight: bold;
      margin-top: 20px;
      transition: background-color 0.3s ease;
    }
    .btn-acessar:hover {
      background-color: #e6c200;
    }
    .footer {
      background-color: #fff; /* Branco */
      color: #000; /* Preto */
      text-align: center;
      padding: 15px 10px;
      font-size: 14px;
      border-top: 1px solid #ddd;
    }
    .footer img {
      width: 30px;
      vertical-align: middle;
    }
    @media(max-width: 600px) {
      .content {
        padding: 20px 15px;
      }
    }
  </style>
</head>
<body>
  <div class="email-wrapper">
    <div class="email-container">
      <!-- Header -->
      <div class="header">
        <img src="{{ asset($barbershop->logo) }}" alt="Logo {{ $barbershop->name }}">
        <h1>{{ $barbershop->name }}</h1>
      </div>
      <!-- Conteúdo -->
      <div class="content">
        <h2>Olá {{ $barber->first_name }},</h2>
        <p>
          Você foi associado à barbearia <strong>{{ $barbershop->name }}</strong>.<br>
          Agora você pode acessar diversas funcionalidades e otimizar sua rotina com nossas ferramentas.
        </p>
        <a href="https://rasoio.petertecnet.com.br" class="btn-acessar">Acessar o RASOIO</a>
      </div>
      <!-- Footer -->
      <div class="footer">
        <p>© {{ date('Y') }} Peter Tecnet. <img src="https://petertecnet.com.br/images/peterlogo.png" alt="Logo Peter Tecnet"> Todos os direitos reservados.</p>
      </div>
    </div>
  </div>
</body>
</html>
