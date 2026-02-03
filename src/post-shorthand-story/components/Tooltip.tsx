import cx from "classnames";
import * as React from "react";

import styles from "./Tooltip.module.scss";

export interface ITooltipProps {
  content?: string;
  message?: string;
}

export function Tooltip({ message, content, children }: React.PropsWithChildren<ITooltipProps>): React.JSX.Element {
  return (
    <div className={styles.tooltipContainer}>
      {children}
      <div className={styles.tooltipPanel}>
        <p className={styles.tooltipMessage}>{message}</p>
        <p className={styles.tooltipDetail}>{content}</p>
      </div>
    </div>
  );
}
