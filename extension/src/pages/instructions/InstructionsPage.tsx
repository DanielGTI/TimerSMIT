import { useEffect, useMemo, useState, type ReactNode } from "react";
import { StatusBadge } from "../../components/StatusBadge";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { fetchCurrentSession, type CurrentSession, type PolicyRules } from "../../lib/api/me";

type OvertimeInfo = NonNullable<CurrentSession["overtime"]>;

const SECTIONS = [
  { id: "resumo", label: "Em resumo" },
  { id: "lancar", label: "Lançar horas" },
  { id: "timer", label: "Timer" },
  { id: "semana", label: "A semana" },
  { id: "aprovacao", label: "Aprovação" },
  // Só para administradores: regras de hora extra, perfis, classificação e banco de horas.
  { id: "adicionais", label: "Horas adicionais", adminOnly: true },
  { id: "limites", label: "Regras e limites" },
  { id: "dicas", label: "Para não perder horas" },
  { id: "outras", label: "Relatórios e Configuração" },
  { id: "duvidas", label: "Dúvidas comuns" },
];

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }): JSX.Element {
  return (
    <section className="card guide" id={id} aria-labelledby={`${id}-title`}>
      <h2 id={`${id}-title`}>{title}</h2>
      {children}
    </section>
  );
}

const plural = (value: number, one: string, many: string) => `${value} ${value === 1 ? one : many}`;

/** Os limites vêm do servidor: o texto nunca fica diferente do que o sistema realmente aplica. */
function limitItems(policy: PolicyRules | null): ReactNode {
  if (!policy) {
    return (
      <li className="muted">
        Os valores desta organização estão carregando. Quem administra os define em Configuração → Regras.
      </li>
    );
  }

  const stepped = policy.durationIncrementMinutes > 1;
  const minimum = policy.minDurationMinutes ?? 1;

  return (
    <>
      <li>
        <strong>Limite por dia: {plural(policy.dailyLimitHours, "hora", "horas")}.</strong> É a soma de todos os seus
        lançamentos da data, de qualquer item. Se passar disso, o sistema recusa, inclusive ao editar um lançamento.
      </li>
      <li>
        <strong>Lançamento retroativo: até {plural(policy.retroactiveWindowDays, "dia", "dias")} para trás.</strong>{" "}
        Datas mais antigas são recusadas (“fora da janela permitida”). Não deixe para lançar depois.
      </li>
      <li>
        <strong>
          {stepped
            ? `Duração em múltiplos de ${policy.durationIncrementMinutes} minutos.`
            : "Duração livre, minuto a minuto."}
        </strong>{" "}
        {stepped
          ? `Ex.: ${policy.durationIncrementMinutes} e ${policy.durationIncrementMinutes * 2} minutos passam; valores no meio, não.`
          : "Qualquer duração maior que zero é aceita."}
      </li>
      {minimum > 1 && (
        <li>
          <strong>Duração mínima: {plural(minimum, "minuto", "minutos")}.</strong> Lançamentos manuais mais curtos são
          recusados. O timer grava o tempo exato.
        </li>
      )}
      <li>
        <strong>{policy.commentRequired ? "Comentário obrigatório" : "Comentário opcional"}</strong> nos lançamentos
        manuais
        {policy.commentRequired
          ? ": sem ele, o sistema recusa o lançamento."
          : ", mas ajuda quem aprova a entender o que foi feito."}
      </li>
    </>
  );
}

/**
 * Instruções de uso: o que a ferramenta faz, as regras de aprovação e os
 * limites. Texto fixo e curto; só os limites vêm do servidor (GET /api/me).
 */
export function InstructionsPage(): JSX.Element {
  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);
  const [policy, setPolicy] = useState<PolicyRules | null>(null);
  const [overtime, setOvertime] = useState<OvertimeInfo | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);

  useEffect(() => {
    let cancelled = false;
    // Sem acesso ao servidor a página continua útil; só os números dos limites ficam de fora.
    fetchCurrentSession(client)
      .then((session) => {
        if (cancelled) return;
        setPolicy(session.policy ?? null);
        setOvertime(session.overtime ?? null);
        setIsAdmin(session.isAdmin === true);
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [client]);

  return (
    <div className="page page--wide guide-page">
      <header className="card guide-hero">
        <h1>Como usar o Controle de horas</h1>
        <p>
          Leitura de poucos minutos: como lançar suas horas, como a semana vai para aprovação e quais limites existem para
          você não perder nada.
        </p>
        <nav aria-label="Seções desta página" className="guide-index">
          {SECTIONS.filter((section) => !("adminOnly" in section) || isAdmin).map((section) => (
            <a key={section.id} href={`#${section.id}`} className="btn btn--chip">
              {section.label}
            </a>
          ))}
        </nav>
      </header>

      <Section id="resumo" title="Em resumo">
        <ol className="guide-steps">
          <li>
            <strong>Lance</strong> suas horas: no work item (aba “Controle de horas”) ou pelo calendário da Folha semanal.
          </li>
          <li>
            <strong>Confira</strong> na Folha semanal. Enquanto a semana está <StatusBadge status="open" />, você pode editar
            e excluir.
          </li>
          <li>
            <strong>Envie a semana</strong> quando terminar. Ela passa a <StatusBadge status="submitted" /> e fica
            bloqueada.
          </li>
          <li>
            Um <strong>aprovador</strong> aprova (a semana fica <StatusBadge status="approved" />) ou rejeita com um motivo
            (<StatusBadge status="rejected" />); nesse caso você corrige e reenvia.
          </li>
        </ol>
        <p className="muted">
          A unidade de tudo é a <strong>semana, de segunda a domingo</strong>. Cada semana é enviada e aprovada por inteiro.
        </p>
      </Section>

      <Section id="lancar" title="Lançar horas">
        <div className="guide-cols">
          <div>
            <h3>Pelo work item</h3>
            <p>
              Abra a tarefa (ou história, bug…) no Azure Boards e entre na aba <strong>Controle de horas</strong>. O item já
              vem preenchido: é só informar o tempo.
            </p>
          </div>
          <div>
            <h3>Pelo calendário</h3>
            <p>
              Em <strong>Folha semanal</strong>, passe o mouse no dia do <em>Resumo mensal</em> e clique no{" "}
              <span className="guide-plus" aria-hidden="true">
                +
              </span>{" "}
              do canto. Também serve o botão <strong>+ Adicionar tempo</strong>. Aí é só buscar o item.
            </p>
          </div>
        </div>

        <div className="guide-demo" aria-hidden="true">
          <div className="guide-demo__day">
            <span className="calendar__number">15</span>
            <span className="calendar__hours muted">–</span>
            <span className="guide-plus guide-plus--float">+</span>
          </div>
          <span className="guide-demo__arrow">→</span>
          <div className="guide-demo__search">
            <span className="guide-demo__field">Buscar work item: 15</span>
            <span>#15796 (Reunião) Alinhamento com cliente</span>
            <span>#15760 Daily 15/09/2026</span>
          </div>
        </div>

        <ul>
          <li>
            <strong>Buscar o item:</strong> digite o número (aparecem todos que começam com ele) ou parte do título. Com o
            campo vazio, aparecem os itens atribuídos a você. Passe o mouse no item escolhido para ver o projeto, o item
            pai e o título inteiro.
          </li>
          <li>
            <strong>Duração:</strong> em horas e minutos (<code>01:30</code>). Os botões +0,5h, +1h, +2h e +4h somam ao que
            já está no campo.
          </li>
          <li>
            <strong>De / Até:</strong>{" "}
            {overtime?.requireTimeOfDay
              ? "obrigatório: é o horário que separa o expediente das horas adicionais."
              : "opcional; sem preencher, vale só a data e a duração."}{" "}
            O horário não pode passar da meia-noite.
          </li>
          <li>
            <strong>Atividade:</strong> escolha a atividade. Só em projetos que cobram o cliente por hora aparece também
            “Horas faturáveis”.
          </li>
        </ul>
      </Section>

      <Section id="timer" title="Timer">
        <ul>
          <li>
            Em vez de digitar, use <strong>Iniciar timer</strong> no item e <strong>Parar timer</strong> ao terminar: o
            tempo vira um lançamento.
          </li>
          <li>
            <strong>Só um timer por vez.</strong> Para começar outro item, pare o atual.
          </li>
          <li>Se o timer atravessar a meia-noite, o tempo é dividido entre os dias automaticamente.</li>
          <li>
            Esqueceu o timer ligado? Pare e corrija a duração em Folha semanal → <em>Lançamentos</em> → Editar.
          </li>
          <li>
            Com timer ativo <strong>não dá para enviar a semana</strong>: pare-o antes.
          </li>
        </ul>
      </Section>

      <Section id="semana" title="A semana e seus estados">
        <div className="guide-flow" aria-label="Estados da semana">
          <StatusBadge status="open" />
          <span aria-hidden="true">enviar →</span>
          <StatusBadge status="submitted" />
          <span aria-hidden="true">aprovar →</span>
          <StatusBadge status="approved" />
          <span aria-hidden="true" className="guide-flow__break">
            ou, se rejeitada:
          </span>
          <StatusBadge status="rejected" />
          <span aria-hidden="true">corrigir e reenviar →</span>
          <StatusBadge status="submitted" />
        </div>

        <div className="table-scroll">
          <table className="guide-table">
            <caption className="sr-only">O que dá para fazer em cada estado da semana</caption>
            <thead>
              <tr>
                <th scope="col">Estado</th>
                <th scope="col">O que significa</th>
                <th scope="col">O que você pode fazer</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <th scope="row">
                  <StatusBadge status="open" />
                </th>
                <td>Em preenchimento.</td>
                <td>Lançar, editar, excluir e enviar.</td>
              </tr>
              <tr>
                <th scope="row">
                  <StatusBadge status="submitted" />
                </th>
                <td>Aguardando o aprovador.</td>
                <td>
                  Nada é editado. Esqueceu uma hora? Use <strong>Cancelar envio</strong> (ou o + do dia){" "}
                  <strong>enquanto ninguém decidiu</strong>: a semana volta a Aberta.
                </td>
              </tr>
              <tr>
                <th scope="row">
                  <StatusBadge status="rejected" />
                </th>
                <td>O aprovador pediu correção e deixou um motivo (aparece no topo da folha).</td>
                <td>Corrigir e enviar de novo.</td>
              </tr>
              <tr>
                <th scope="row">
                  <StatusBadge status="approved" />
                </th>
                <td>Fechada.</td>
                <td>Nada. Só um administrador reabre, com justificativa.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p className="muted">
          Cada reenvio gera uma nova revisão, e o histórico de decisões (quem, quando e por quê) fica registrado na própria
          folha.
        </p>
      </Section>

      <Section id="aprovacao" title="Como funciona a aprovação">
        <ul>
          <li>
            <strong>Quem aprova:</strong> os aprovadores designados para você (definidos por um administrador) e, sempre,
            os administradores. Isso é decidido <em>no momento do envio</em>.
          </li>
          <li>
            <strong>Ninguém aprova a própria semana</strong>, exceto administrador.
          </li>
          <li>
            O aprovador vê na tela <strong>Aprovações</strong> as semanas pendentes e abre cada uma para conferir a grade e
            os lançamentos. Pode <strong>aprovar</strong> ou <strong>rejeitar</strong>; a rejeição exige um motivo, que
            você lê na sua folha.
          </li>
          <li>
            Aprovou, fechou: a semana não aceita mais criar, editar nem excluir lançamentos. Para mexer, um administrador
            precisa <strong>reabrir</strong> (com justificativa). Depois de lançar o que faltava, envie de novo.
          </li>
          <li>
            Sem nenhum aprovador configurado, o envio é recusado com a mensagem “Nenhum aprovador configurado”: avise um
            administrador.
          </li>
        </ul>
      </Section>

      {isAdmin && (
        <Section id="adicionais" title="Horas adicionais: hora extra e banco de horas">
          <ul>
            <li>
              <strong>O que conta como hora adicional:</strong> em dia útil, o que você trabalhar{" "}
              <strong>
                fora do expediente
                {overtime ? ` (${overtime.workdayStart} às ${overtime.workdayEnd})` : ""}
              </strong>
              ; em <strong>sábado, domingo e feriado</strong>, o dia todo.
            </li>
            <li>
              Por isso, nos lançamentos manuais, <strong>o horário (De/Até) é obrigatório</strong>: é ele que separa o
              expediente do que veio depois. O timer já registra o horário sozinho.
            </li>
            <li>
              Cada hora adicional é multiplicada pelo <strong>fator do dia</strong>, definido pelo administrador conforme a
              convenção. Ex.: 10 horas num sábado com fator 1,5 contam como 15 horas. Das 22h às 5h há também o adicional
              noturno.
            </li>
            <li>
              <strong>Hora adicional precisa de autorização.</strong> Combine antes com o seu gestor: não é para fazer por
              conta própria. Ao aprovar a semana, o aprovador valida essas horas ou marca como <em>não autorizada</em>, com
              o motivo.
            </li>
            <li>
              <strong>Informar hora extra:</strong> na Folha semanal, em “Horas extras”, avise o dia (ou período), quantas
              horas por dia e o motivo. Dá para informar antes de fazer ou depois, para regularizar. O aprovador decide em
              Aprovações → Horas extras e pode liberar menos horas do que o pedido. Hora extra lançada sem pedido aprovado
              aparece como <strong>“Horas extras, sujeitas à aprovação”</strong>.
            </li>
            <li>
              Cada pessoa tem um <strong>perfil de hora extra</strong>, definido pelo administrador. Na maioria dos casos é o
              padrão, descrito acima. Algumas pessoas têm a hora extra <strong>pré-aprovada</strong> e não precisam informar
              antes. Outras têm a hora extra <strong>restrita</strong>: o trecho fora do expediente sem pedido aprovado não
              entra como lançamento, vira <strong>hora extra a confirmar</strong> (com motivo e “Entendi”) e só conta se o
              aprovador confirmar. Recusada, não são horas a receber.
            </li>
            <li>
              Depois da aprovação, o administrador decide o destino: <strong>hora extra</strong> (paga),{" "}
              <strong>banco de horas</strong> (vira folga depois; só para CLT) ou <strong>a pagar</strong> (PJ). Até lá,
              na sua folha aparece <strong>“Horas adicionais a validar”</strong>.
            </li>
            <li>
              Onde ver: na Folha semanal, embaixo da duração de cada lançamento, e no total da semana (“Horas adicionais”).
            </li>
            <li>
              <strong>Banco de horas:</strong> o saldo fica no fim da Folha semanal, com o extrato. As horas entram quando o
              administrador as manda para o banco e saem nas folgas que ele lança. Cada crédito{" "}
              <strong>vence no prazo do acordo</strong> (em geral 6 meses), e uma folga usa primeiro o que vence antes. O que
              vencer sem uso é pago como hora extra. Quer folgar? Combine com o gestor; quem lança a folga é o administrador.
            </li>
          </ul>
          {overtime && !overtime.enabled && (
            <p className="muted">O controle de horas adicionais está desligado nesta organização no momento.</p>
          )}
        </Section>
      )}

      <Section id="limites" title="Regras e limites">
        <p className="muted">Valores desta organização, lidos do sistema:</p>
        <ul>{limitItems(policy)}</ul>
        <ul>
          <li>
            <strong>Acesso por projeto:</strong> só lança quem tem papel naquele projeto. Apareceu “Sem permissão neste
            projeto”? Peça a um administrador em Configuração → Pessoas e papéis. Projeto desabilitado também não aceita
            lançamentos.
          </li>
          <li>
            <strong>Semana enviada ou aprovada</strong> bloqueia criar, editar, excluir e até parar um timer que estava
            correndo.
          </li>
          <li>
            A <strong>data do lançamento</strong> segue o fuso da organização; lançamentos antigos mantêm o fuso de quando
            foram feitos.
          </li>
          <li>Mudar uma regra vale só para lançamentos novos: o que já foi lançado não é recalculado.</li>
        </ul>
      </Section>

      <Section id="dicas" title="Para não perder horas">
        <ul>
          <li>Lance no mesmo dia, ou logo no seguinte: a janela retroativa é limitada e, depois dela, o sistema recusa.</li>
          <li>Ao fim do dia, confira o total na Folha semanal e veja se não ficou timer ativo.</li>
          <li>Envie a semana assim que fechar: quanto antes, mais cedo o aprovador vê.</li>
          <li>
            Rejeitada? Leia o motivo no aviso no topo da folha, corrija e reenvie. As horas que você lançou não se perdem.
          </li>
        </ul>
      </Section>

      <Section id="outras" title="Relatórios e Configuração">
        <ul>
          <li>
            <strong>Relatórios:</strong> você sempre vê as <em>suas</em> horas. Gestores veem as dos projetos que
            gerenciam e administradores, as da organização. Há filtros (período, pessoa, projeto, atividade, estado
            da semana e work item), agrupamento em grade e <strong>Exportar CSV</strong> com os filtros aplicados.
            Na aba <strong>Detalhada</strong>, administradores corrigem ou excluem um lançamento lançado errado pelo
            lápis no começo da linha (só em semana aberta; a correção fica registrada na auditoria).
          </li>
          <li>
            <strong>Configuração</strong> (só administradores): regras e fuso, horas extras e feriados, classificação
            das horas adicionais, banco de horas (folgas e ajustes), fechamento do mês para o DP (com CSV), projetos,
            atividades, pessoas e papéis, e aprovadores designados.
          </li>
        </ul>
      </Section>

      <Section id="duvidas" title="Dúvidas comuns">
        <dl className="guide-faq">
          <dt>Não vejo o + no dia.</dt>
          <dd>
            Ele aparece ao passar o mouse sobre o dia. Em semana enviada ou aprovada, o + pede para cancelar o envio ou
            reabrir antes de lançar.
          </dd>
          <dt>“Limite diário de horas excedido para esta data.”</dt>
          <dd>A soma do dia passaria do limite. Confira os lançamentos daquela data e ajuste.</dd>
          <dt>“Data fora da janela permitida para lançamento retroativo.”</dt>
          <dd>A data é mais antiga do que a regra permite. Fale com um administrador se for um caso justificado.</dd>
          <dt>“Semana enviada ou aprovada: edição bloqueada.”</dt>
          <dd>Cancele o envio (se estiver Enviada) ou peça a reabertura (se Aprovada).</dd>
          <dt>“Informe o horário (De/Até)…”</dt>
          <dd>
            Com o controle de horas adicionais ligado, todo lançamento manual precisa de horário de início e fim.
          </dd>
          <dt>Minha hora adicional aparece como “a validar”.</dt>
          <dd>É o normal até a semana ser aprovada e o administrador classificar (hora extra, banco ou a pagar).</dd>
          <dt>Meu saldo do banco de horas diminuiu sem eu folgar.</dt>
          <dd>
            Provavelmente um crédito venceu. No extrato aparece a linha “Vencido (hora extra a pagar)”: essas horas não se
            perdem, são pagas como hora extra.
          </dd>
          <dt>“Já existe timer ativo para outro item.”</dt>
          <dd>Pare o timer que está correndo antes de iniciar outro.</dd>
          <dt>Não acho meu item na busca.</dt>
          <dd>A busca mostra só o que você tem permissão de ver no Azure DevOps. Tente o número exato do item.</dd>
        </dl>
      </Section>
    </div>
  );
}
