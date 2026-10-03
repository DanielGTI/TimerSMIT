import { getAccessToken, getHostContext, getWebContext } from "./sdk";

/** Uma linha da lista de resultados da busca. */
export interface WorkItemHit {
  id: number;
  title: string;
  workItemType: string;
  projectName: string;
  state: string | null;
}

/** O item escolhido, com o que o lançamento precisa (projeto) e o que o detalhe mostra (pai). */
export interface WorkItemDetails extends WorkItemHit {
  /** GUID do projeto no Azure DevOps; null se não deu para descobrir. */
  projectId: string | null;
  iterationPath: string | null;
  parent: { id: number; title: string; workItemType: string } | null;
  webUrl: string;
}

interface WorkItemResponse {
  id: number;
  fields?: Record<string, unknown>;
  url?: string;
  _links?: Record<string, { href?: string } | undefined>;
}

const API_VERSION = "7.1";
const MAX_RESULTS = 50;
/** IDs da organização têm até 7 dígitos; além disso a busca por prefixo não faz sentido. */
const MAX_ID_DIGITS = 7;
const LIST_FIELDS = ["System.Id", "System.Title", "System.WorkItemType", "System.TeamProject", "System.State"];
const GUID = /([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/_apis\//i;

const quote = (text: string): string => `'${text.replace(/'/g, "''")}'`;

/**
 * "15" encontra o #15 e todo ID que começa com 15 (150–159, 1500–1599…),
 * como no 7pace. O WIQL não tem "começa com" para números, então cada
 * tamanho de ID vira uma faixa.
 */
function idPrefixClauses(digits: string): string[] {
  if (!/^[1-9]\d*$/.test(digits) || digits.length > MAX_ID_DIGITS) return [];

  const base = Number(digits);
  const clauses = [`[System.Id] = ${base}`];
  for (let extra = 1; digits.length + extra <= MAX_ID_DIGITS; extra++) {
    const scale = 10 ** extra;
    clauses.push(`([System.Id] >= ${base * scale} AND [System.Id] <= ${base * scale + scale - 1})`);
  }
  return clauses;
}

/**
 * Consulta WIQL da busca: número (prefixo do ID) ou trecho do título. Sem
 * texto, lista os itens atribuídos a quem está logado. Busca na organização
 * inteira — quem trabalha para vários clientes não precisa trocar de projeto.
 * Devolve null quando não há o que buscar (ex.: uma letra só).
 */
export function buildSearchWiql(text: string): string | null {
  const trimmed = text.trim();
  const digits = trimmed.replace(/^#/, "");

  let match: string;
  if (trimmed === "") {
    match = "[System.AssignedTo] = @Me";
  } else {
    const clauses = idPrefixClauses(digits);
    if (trimmed.length >= 2) clauses.push(`[System.Title] CONTAINS ${quote(trimmed)}`);
    if (clauses.length === 0) return null;
    match = `(${clauses.join(" OR ")})`;
  }

  return `SELECT [System.Id] FROM WorkItems WHERE ${match} AND [System.State] <> 'Removed' ORDER BY [System.ChangedDate] DESC`;
}

async function devopsContext() {
  const [host, token] = await Promise.all([getHostContext(), getAccessToken()]);
  return { base: `https://dev.azure.com/${encodeURIComponent(host.name)}`, token };
}

async function readJson<T>(response: Response): Promise<T> {
  if (response.status === 401 || response.status === 403) {
    throw new Error("O Azure DevOps não permitiu buscar work items. Confirme que a extensão está autorizada a ler work items.");
  }
  if (!response.ok) {
    throw new Error(`Não foi possível buscar work items no Azure DevOps (erro ${response.status}).`);
  }
  return (await response.json()) as T;
}

const text = (value: unknown): string => (typeof value === "string" ? value : "");

function toHit(item: WorkItemResponse): WorkItemHit {
  const fields = item.fields ?? {};
  return {
    id: item.id,
    title: text(fields["System.Title"]),
    workItemType: text(fields["System.WorkItemType"]),
    projectName: text(fields["System.TeamProject"]),
    state: text(fields["System.State"]) || null,
  };
}

function workItemsUrl(base: string, ids: number[], fields: string[]): string {
  const query = new URLSearchParams({
    ids: ids.join(","),
    fields: fields.join(","),
    errorPolicy: "omit",
    "api-version": API_VERSION,
  });
  return `${base}/_apis/wit/workitems?${query.toString()}`;
}

/**
 * Busca work items com o acesso de quem está logado (só aparece o que a
 * pessoa pode ver). O ID exato digitado vem primeiro; o resto segue a ordem
 * de alteração mais recente.
 */
export async function searchWorkItems(query: string, fetchImpl: typeof fetch = fetch): Promise<WorkItemHit[]> {
  const wiql = buildSearchWiql(query);
  if (!wiql) return [];

  const { base, token } = await devopsContext();
  const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };

  const found = await readJson<{ workItems?: Array<{ id: number }> }>(
    await fetchImpl(`${base}/_apis/wit/wiql?$top=${MAX_RESULTS}&api-version=${API_VERSION}`, {
      method: "POST",
      headers: { ...headers, "Content-Type": "application/json" },
      body: JSON.stringify({ query: wiql }),
    }),
  );
  const ids = (found.workItems ?? []).map((item) => item.id).slice(0, MAX_RESULTS);
  if (ids.length === 0) return [];

  const page = await readJson<{ value?: Array<WorkItemResponse | null> }>(
    await fetchImpl(workItemsUrl(base, ids, LIST_FIELDS), { headers }),
  );
  const byId = new Map((page.value ?? []).filter((item): item is WorkItemResponse => !!item).map((item) => [item.id, toHit(item)]));
  const hits = ids.map((id) => byId.get(id)).filter((hit): hit is WorkItemHit => !!hit);

  const exact = Number(query.trim().replace(/^#/, ""));
  return [...hits.filter((hit) => hit.id === exact), ...hits.filter((hit) => hit.id !== exact)];
}

/**
 * O GUID do projeto não vem nos campos (System.TeamProject é o nome); ele
 * aparece nos links do item. Se faltar, vale o projeto aberto quando o nome
 * bate.
 */
function projectIdOf(item: WorkItemResponse, projectName: string, current: { id: string; name: string } | undefined) {
  for (const href of [item._links?.workItemType?.href, item._links?.fields?.href, item.url]) {
    const match = href ? GUID.exec(href) : null;
    if (match) return match[1];
  }
  return current && current.name === projectName ? current.id : null;
}

/** Lê o item escolhido e o pai dele (para o detalhe "Projeto / … / #pai"). */
export async function fetchWorkItemDetails(id: number, fetchImpl: typeof fetch = fetch): Promise<WorkItemDetails> {
  const [{ base, token }, webContext] = await Promise.all([devopsContext(), getWebContext()]);
  const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };

  const item = await readJson<WorkItemResponse>(
    await fetchImpl(`${base}/_apis/wit/workitems/${id}?$expand=all&api-version=${API_VERSION}`, { headers }),
  );
  const hit = toHit(item);
  const fields = item.fields ?? {};

  let parent: WorkItemDetails["parent"] = null;
  const parentId = Number(fields["System.Parent"]);
  if (Number.isInteger(parentId) && parentId > 0) {
    // Sem permissão no pai (ou falha ao lê-lo), ele só não aparece no detalhe.
    try {
      const page = await readJson<{ value?: Array<WorkItemResponse | null> }>(
        await fetchImpl(workItemsUrl(base, [parentId], ["System.Title", "System.WorkItemType"]), { headers }),
      );
      const found = page.value?.find((candidate) => candidate?.id === parentId);
      if (found) {
        const parentHit = toHit(found);
        parent = { id: parentId, title: parentHit.title, workItemType: parentHit.workItemType };
      }
    } catch {
      parent = null;
    }
  }

  return {
    ...hit,
    projectId: projectIdOf(item, hit.projectName, webContext.project),
    iterationPath: text(fields["System.IterationPath"]) || null,
    parent,
    webUrl: `${base}/${encodeURIComponent(hit.projectName)}/_workitems/edit/${id}`,
  };
}

/** Cores padrão dos tipos do Azure DevOps (processos Agile/Scrum/Basic/CMMI). */
const TYPE_COLORS: Record<string, string> = {
  task: "#F2CB1D",
  bug: "#CC293D",
  "user story": "#009CCC",
  "product backlog item": "#009CCC",
  requirement: "#009CCC",
  issue: "#B4009E",
  impediment: "#B4009E",
  feature: "#773B93",
  epic: "#FF7B00",
  "test case": "#004B50",
};

export function workItemTypeColor(workItemType: string): string {
  return TYPE_COLORS[workItemType.toLowerCase()] ?? "#808080";
}

/**
 * GUID de cada projeto no Azure DevOps a partir de um work item dele (o
 * endereço do item traz o GUID do projeto). Serve à importação do 7pace, que
 * só traz o nome do projeto. Só entra o projeto cujo nome bate com o do item.
 */
export async function resolveProjectIds(
  samples: Array<{ name: string; workItemId: number }>,
  fetchImpl: typeof fetch = fetch,
): Promise<Record<string, string>> {
  if (samples.length === 0) return {};

  const { base, token } = await devopsContext();
  const page = await readJson<{ value?: Array<WorkItemResponse | null> }>(
    await fetchImpl(workItemsUrl(base, samples.map((sample) => sample.workItemId), ["System.TeamProject"]), {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    }),
  );

  const resolved: Record<string, string> = {};
  for (const item of page.value ?? []) {
    if (!item) continue;
    const sample = samples.find((candidate) => candidate.workItemId === item.id);
    const project = String(item.fields?.["System.TeamProject"] ?? "");
    const guid = item.url ? GUID.exec(item.url)?.[1] : undefined;
    if (sample && guid && project.toLocaleLowerCase("pt-BR") === sample.name.toLocaleLowerCase("pt-BR")) {
      resolved[sample.name] = guid.toLowerCase();
    }
  }

  return resolved;
}
