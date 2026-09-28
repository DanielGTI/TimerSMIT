# SMIT TimeTrack — pacote Spec Kit

Especificação SDD do MVP de uma extensão de controle de horas para Azure DevOps Services. Este pacote contém documentos de planejamento, sem aplicação implementada.

- `.specify/memory/constitution.md`: princípios e gates do produto.
- `specs/001-azure-devops-timetracker/spec.md`: cenários, requisitos e critérios de sucesso.
- `plan.md`: plano de implementação e estrutura proposta.
- `research.md`: decisões, fontes e provas pendentes.
- `data-model.md`: entidades, estados e regras temporais.
- `contracts/openapi.yaml`: contrato inicial sujeito à prova de autenticação.
- `tasks.md`: tarefas executáveis por fase e história.
- `quickstart.md`: roteiro para iniciar piloto privado.

Copie a pasta para a raiz de um repositório criado com GitHub Spec Kit. A estrutura segue os modelos atuais de `constitution`, `spec`, `plan` e `tasks`, preenchidos como proposta; não foi gerada por execução do CLI em um repositório existente. Antes de implementar, resolva as decisões CL-001–CL-004, especialmente autenticação do backend.

Fonte do formato: https://github.com/github/spec-kit
