# Cognitive Research Core — runtime

O Cognitive Research Core é um módulo experimental e genérico da API Peter Tecnet. Ele mantém identidade persistente, observações, memórias, crenças, evidências, contradições, objetivos controlados, snapshots de estado e experimentos de pesquisa.

## Ativação

Em produção, o núcleo fica habilitado por padrão quando `COGNITION_ENABLED` não é definido explicitamente. O kill switch continua disponível: `COGNITION_ENABLED=false` desliga a coleta/aprendizagem cognitiva sem remover dados existentes.

Configuração recomendada de produção:

```env
COGNITION_ENABLED=true
COGNITION_AUTO_LEARN=true
COGNITION_DEFAULT_AGENT=ecosystem-core
COGNITION_DEFAULT_AGENT_NAME="Peter Cognitive Core"
COGNITION_MEMORY_THRESHOLD=0.65
COGNITION_ALLOW_SELF_GENERATED_GOALS=false
COGNITION_QUEUE_CONNECTION=sync
COGNITION_QUEUE=default
```

`local` e `testing` permanecem opt-in quando `COGNITION_ENABLED` não é definido, evitando que dados de desenvolvimento/teste sejam aprendidos por acidente.

## Aprendizado contínuo

Interações registradas no domínio genérico `Interaction` alimentam o fluxo:

`Interaction -> CognitiveObservation -> Memory/Belief -> CognitiveLearningEvent -> State`

O conteúdo aprendido é sanitizado e restringido por allowlist. Segredos, tokens, credenciais e fragmentos de PII configurados em `config/cognition.php` não devem entrar no conteúdo cognitivo.

A conexão cognitiva usa `sync` por padrão para garantir processamento mesmo quando não existe worker de fila dedicado. Em escala maior, `COGNITION_QUEUE_CONNECTION` pode ser migrado para uma conexão assíncrona supervisionada sem mudar o modelo cognitivo.

## Segurança

- `COGNITION_ALLOW_SELF_GENERATED_GOALS=false` por padrão.
- A API cognitiva exige autenticação e permissão `ecosystem_manage`.
- O aprendizado pode ser pausado por agente através de `learning_enabled`.
- O kill switch global `COGNITION_ENABLED=false` continua disponível.
- Resultados dos experimentos representam evidências funcionais e não constituem prova de consciência subjetiva.

## Observabilidade

O Admin Center consome `GET /api/cognition/agents/{agent}/dashboard` e expõe o Centro Cognitivo em `/admin/cognition`, incluindo confiança, contradições, memórias, crenças, eventos de aprendizagem, timeline, snapshots e experimentos.

O pipeline de deploy Laravel registra o estado efetivo de `cognition.enabled`, `cognition.auto_learn` e `cognition.queue_connection`, permitindo validar o runtime sem depender de inspeção manual do `.env`.
