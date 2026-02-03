import { CircleCheck, MessageCircleWarning } from "lucide-react";
import * as React from "react";

import { IStoryError, IStoryProgress } from "../hooks/useStoryState";
import styles from "./StoryStatus.module.scss";

interface IStoryStatusProps {
  latest: number | null;
  live: number | null;
  progress: IStoryProgress | null;
  error?: IStoryError;
}

export function StoryStatus({ latest, live, progress, error, ...props }: IStoryStatusProps): React.JSX.Element | null {
  if (!progress && latest === null) {
    return null;
  }

  return (
    <p className={styles.storyStatusText} {...props}>
      {getStatusText()}
    </p>
  );

  function getStatusText(): JSX.Element {
    if (progress) {
      return progress.status ? <em>{progress.status}</em> : <>Publishing in progress&hellip;</>;
    }

    if (error?.code?.startsWith("BILLING_") || error?.code === "INSUFFICIENT_CREDIT") {
      const action = live === null ? "published" : "updated";
      return (
        <>
          <MessageCircleWarning size="1rem" color="hsla(4, 86%, 58%, 1)" />
          The story cannot be {action} until this issue is resolved.
        </>
      );
    }

    if (live === null) {
      return <>This story is ready for publishing.</>;
    }

    if (live !== latest) {
      return (
        <>
          <MessageCircleWarning size="1rem" color="hsla(28,97%, 44%, 1)" />
          This story has unpublished changes.
        </>
      );
    }

    return (
      <>
        <CircleCheck size="1rem" color="hsla(153,91%, 30%, 1)" />
        All changes have been published.
      </>
    );
  }
}
