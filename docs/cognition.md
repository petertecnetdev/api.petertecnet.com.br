# Peter Tecnet Cognitive Research Core

Arquitetura cognitiva experimental, persistente e genérica para aprendizado contínuo no ecossistema Peter Tecnet.

> Este módulo implementa indicadores funcionais estudados na ciência da consciência. Ele não afirma nem prova consciência subjetiva.

## Persistência

- `cognitive_agents`: identidade, propósito, capacidades, limites, valores e self-model.
- `cognitive_observations`: eventos sanitizados percebidos pelo agente.
- `cognitive_memories`: memórias episódicas, semânticas, procedurais, de self e feedback.
- `cognitive_beliefs`: crenças apoiadas por evidências, com confiança e contradições.
- `cognitive_goals`: objetivos explícitos de sistema/usuário; autogeração fica desabilitada por padrão.
- `cognitive_state_snapshots`: atenção, self-model, world-model, working memory, incertezas e objetivos ativos.
- `cognitive_experiments` e `cognitive_experiment_runs`: protocolos e evidências experimentais.
- `cognitive_learning_events`: trilha reversível explicando cada mudança de memória/crença.

## Aprendizado pelo uso

Com `COGNITION_ENABLED=true` e `COGNITION_AUTO_LEARN=true`, cada nova `Interaction` é processada por job. O fluxo automático copia somente campos operacionais permitidos. PII, credenciais, tokens, identificadores de rede, documentos e pagamento são excluídos por padrão.

Eventos de alta saliência viram memória episódica. Toda observação atualiza crenças de uso baseadas em evidência. Feedback explícito autorizado pode reforçar ou contradizer uma crença; correções reduzem confiança e ficam auditadas.

## Configuração

```env
COGNITION_ENABLED=true
COGNITION_AUTO_LEARN=true
COGNITION_DEFAULT_AGENT=ecosystem-core
COGNITION_DEFAULT_AGENT_NAME="Peter Cognitive Core"
COGNITION_MEMORY_THRESHOLD=0.65
COGNITION_ALLOW_SELF_GENERATED_GOALS=false
COGNITION_QUEUE=default
```

Execute as migrations antes de ativar.

## API

Todos os endpoints ficam sob `/api/cognition`, exigem JWT e a permissão já existente `ecosystem_manage`.

Principais endpoints:

- `GET /agents/default`
- `GET /agents/{agent}/observations`
- `GET /agents/{agent}/memories`
- `GET /agents/{agent}/beliefs`
- `GET /agents/{agent}/learning-events`
- `POST /agents/{agent}/observations`
- `POST /agents/{agent}/feedback`
- `POST /agents/{agent}/goals`
- `POST /agents/{agent}/state`
- `GET /research/framework`
- `POST /research/bootstrap`
- `GET /research/experiments`
- `POST /agents/{agent}/research/experiments/{experiment}/runs`

## Dimensões experimentais iniciais

Self-model, autorreconhecimento visual/self-world, agência, continuidade temporal, memória que altera comportamento, metacognição, atenção seletiva, attention schema, global workspace, recorrência, erro de previsão, embodiment e regulação homeostática.

O teste do espelho é tratado como indicador de self-model, nunca como prova isolada de consciência.
