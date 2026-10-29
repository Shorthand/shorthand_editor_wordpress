import cx from "classnames";
import * as React from "react";

import styles from "./Tooltip.module.scss";

export interface ITooltipProps {
  content?: string;
  message?: string;
}

export function Tooltip({ message, content, children }: React.PropsWithChildren<ITooltipProps>): React.JSX.Element {
  const panelId = React.useId();

  return (
    <div className={styles.tooltipContainer}>
      <button type="button" className={styles.tooltipTrigger} aria-describedby={panelId}>
        {children}
      </button>
      <div id={panelId} role="tooltip" className={styles.tooltipPanel}>
        <p className={styles.tooltipMessage}>{message}</p>
        <p className={styles.tooltipDetail}>{content}</p>
      </div>
    </div>
  );
}
