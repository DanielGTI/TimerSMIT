interface SwitchProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  /** Nome acessível do interruptor. */
  label: string;
  /** Texto visível ao lado (padrão: o próprio `label`). */
  text?: string;
  disabled?: boolean;
}

export function Switch({ checked, onChange, label, text, disabled }: SwitchProps): JSX.Element {
  return (
    <div className="switch-row">
      <span aria-hidden="true">{text ?? label}</span>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        aria-label={label}
        disabled={disabled}
        className="switch"
        onClick={() => onChange(!checked)}
      >
        <span className="switch__thumb" />
      </button>
    </div>
  );
}
