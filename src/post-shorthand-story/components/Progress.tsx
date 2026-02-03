import cx from "classnames";
import * as React from "react";

import styles from "./Progress.module.scss";

interface IProgressProps {
  max: number;
  value?: number;
  className?: string;
}

export function Progress({ className, max, value = 0 }: Readonly<IProgressProps>) {
  value = (value / max) * 100;
  value = value > 100 ? 100 : value < 0 ? 0 : value;
  return (
    <div className={cx(styles.progressBarContainer, className)}>
      <div className={styles.progressBarIndicator} style={{ transform: `translateX(-${100 - (value || 0)}%)` }} />
    </div>
  );
}
