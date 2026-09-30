import type { ApiClient } from "../../lib/api/client";
import type { Role, SettingsDto } from "../../lib/api/settings";

/** Tudo que uma seção precisa: dados, cliente e o executor de ações (trata erro/ocupado/aviso). */
export interface SectionProps {
  settings: SettingsDto;
  client: ApiClient;
  busy: boolean;
  /** Executa a ação; devolve `true` se deu certo (erros viram mensagem na tela). */
  run: (action: () => Promise<SettingsDto>, success: string) => Promise<boolean>;
}

export const ROLE_LABELS: Record<Role, string> = {
  member: "Membro",
  approver: "Aprovador",
  manager: "Gestor",
  admin: "Administrador",
};

export const ORGANIZATION_SCOPE = "Toda a organização";

/** Fusos mais comuns; o fuso atual da organização entra na lista mesmo que não esteja aqui. */
export const COMMON_TIMEZONES = [
  "America/Sao_Paulo",
  "America/Manaus",
  "America/Belem",
  "America/Fortaleza",
  "America/Recife",
  "America/Bahia",
  "America/Cuiaba",
  "America/Campo_Grande",
  "America/Porto_Velho",
  "America/Rio_Branco",
  "America/Noronha",
  "America/Argentina/Buenos_Aires",
  "America/New_York",
  "America/Chicago",
  "America/Los_Angeles",
  "Europe/Lisbon",
  "Europe/London",
  "Europe/Madrid",
  "UTC",
];
