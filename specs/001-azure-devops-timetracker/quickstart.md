# Quickstart — Desenvolvimento e piloto

Este pacote é uma especificação, não contém código executável. Ao criar o repositório:

1. Inicialize GitHub Spec Kit no projeto com a versão corrente do CLI e preserve `.specify/memory/constitution.md` e `specs/001-azure-devops-timetracker/`.
2. Revise `spec.md` e resolva `CL-001` a `CL-004`, com prioridade para a prova de autenticação em `research.md`.
3. Implemente a estrutura `extension/` e `api/` de `plan.md`; crie publisher de desenvolvimento e organização Azure DevOps de teste.
4. Realize a prova técnica: guia de work item, SDK, leitura com `vso.work` e sessão backend verificável. Registre resultados no `research.md`.
5. Implemente tarefas na ordem de `tasks.md`; rode testes de autorização, concorrência e fusos.
6. Empacote o VSIX com `tfx-cli`, publique como privado, compartilhe com a organização de teste e execute cenários US1–US5.
7. Valide documentação, privacidade e segurança. A extensão é privada (uso interno da SMIT): não há versão pública a preparar.

Documentação oficial: https://github.com/github/spec-kit ; https://learn.microsoft.com/en-us/azure/devops/extend/publish/overview?view=azure-devops
