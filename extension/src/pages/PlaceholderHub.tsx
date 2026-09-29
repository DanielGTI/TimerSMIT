interface PlaceholderHubProps {
  title: string;
  plannedIn: string;
}

/**
 * Placeholder comum às páginas ainda não implementadas (US2-US5). Existe
 * para que os hubs do manifesto tenham um destino real e o contribution ID
 * possa ser validado numa organização de teste (T012) antes das páginas
 * completas serem construídas.
 */
export function PlaceholderHub({ title, plannedIn }: PlaceholderHubProps): JSX.Element {
  return (
    <div>
      <h1>{title}</h1>
      <p>Ainda não implementado — previsto em {plannedIn}.</p>
    </div>
  );
}
