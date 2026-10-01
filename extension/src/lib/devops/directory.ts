import { getAccessToken, getHostContext } from "./sdk";

/** Pessoa com licença ativa na organização do Azure DevOps. */
export interface DirectoryPerson {
  identityId: string;
  displayName: string;
}

/** Só o que usamos de cada direito de acesso (UserEntitlement) devolvido pelo Azure DevOps. */
interface UserEntitlement {
  id?: string;
  user?: { displayName?: string; mailAddress?: string; principalName?: string; subjectKind?: string };
  accessLevel?: { status?: string; accountLicenseType?: string; msdnLicenseType?: string; licensingSource?: string };
}

interface EntitlementPage {
  // O nome da lista mudou entre versões da API; aceitamos as três formas.
  members?: UserEntitlement[];
  items?: UserEntitlement[];
  value?: UserEntitlement[];
  continuationToken?: string | string[] | null;
}

const API_VERSION = "7.1-preview.3";
const PAGE_SIZE = 100;
const MAX_PAGES = 200;

/**
 * "Ativa" = o Azure DevOps considera a conta ativa (`accessLevel.status`) e é
 * uma pessoa (não conta de serviço). Não olhamos o tipo de licença da conta:
 * quem tem assinatura do Visual Studio vem com `accountLicenseType: "none"`
 * (a licença está na assinatura) e é tão ativo quanto os demais. Quem saiu da
 * empresa ou ficou sem licença tem outro status (desabilitado, pendente…).
 */
export function selectActivePeople(entitlements: UserEntitlement[]): DirectoryPerson[] {
  const people: DirectoryPerson[] = [];

  for (const entitlement of entitlements) {
    const { id, user, accessLevel } = entitlement;
    if (!id || accessLevel?.status !== "active") continue;
    if (user?.subjectKind && user.subjectKind !== "user") continue;

    people.push({
      identityId: id,
      displayName: user?.displayName || user?.mailAddress || user?.principalName || id,
    });
  }

  return people;
}

const nextToken = (page: EntitlementPage): string | null => {
  const token = Array.isArray(page.continuationToken) ? page.continuationToken[0] : page.continuationToken;
  return token ? token : null;
};

/**
 * Lê, com o acesso de quem está logado, as pessoas com licença ativa da
 * organização atual. Exige o escopo `vso.memberentitlementmanagement` no
 * manifesto e que quem abre tenha permissão de ver usuários.
 */
export async function fetchActiveDirectoryPeople(fetchImpl: typeof fetch = fetch): Promise<DirectoryPerson[]> {
  const [host, token] = await Promise.all([getHostContext(), getAccessToken()]);
  const base = `https://vsaex.dev.azure.com/${encodeURIComponent(host.name)}/_apis/userentitlements`;

  const all: UserEntitlement[] = [];
  const seen = new Set<string>();
  let continuation: string | null = null;

  for (let pageNumber = 0; pageNumber < MAX_PAGES; pageNumber++) {
    const query = new URLSearchParams({ "api-version": API_VERSION, top: String(PAGE_SIZE) });
    if (continuation) query.set("continuationToken", continuation);

    const response = await fetchImpl(`${base}?${query.toString()}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    });

    if (response.status === 401 || response.status === 403) {
      throw new Error(
        "O Azure DevOps não permitiu ler os usuários da organização. Confirme que a extensão foi reautorizada com o acesso a direitos de membros e que você pode ver os usuários.",
      );
    }
    if (!response.ok) {
      throw new Error(`Não foi possível ler os usuários do Azure DevOps (erro ${response.status}).`);
    }

    const page = (await response.json()) as EntitlementPage;
    const entries = page.members ?? page.items ?? page.value ?? [];

    // Se o servidor ignorar o token de continuação, a mesma página voltaria
    // para sempre: parar quando não surge ninguém novo.
    const fresh = entries.filter((entry) => entry.id && !seen.has(entry.id));
    fresh.forEach((entry) => seen.add(entry.id as string));
    all.push(...fresh);

    continuation = nextToken(page);
    if (!continuation || fresh.length === 0) break;
  }

  return selectActivePeople(all);
}
