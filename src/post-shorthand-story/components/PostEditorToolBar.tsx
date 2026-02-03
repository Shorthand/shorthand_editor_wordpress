import * as React from "react";

import { IStoryError, useStoryState } from "../hooks/useStoryState";
import { EditButton } from "./EditButton";
import styles from "./PostEditorToolBar.module.scss";
import { Progress } from "./Progress";
import { StoryStatus } from "./StoryStatus";
import { StoryUpdateTime } from "./StoryUpdateTime";
import { Tooltip } from "./Tooltip";

interface IPostEditorToolBarProps {
  editUrl: string;
}

export function PostEditorToolBar({ editUrl }: IPostEditorToolBarProps): React.JSX.Element {
  const { errors, progress, liveVersion, updateErrors } = useStoryState();

  const [latestVersion, setLatestVersion] = React.useState<number | null>(null);
  React.useEffect(() => {
    function handleMessage(event: MessageEvent): void {
      if (!event.data) {
        return;
      }

      if (event.origin !== window.location.origin) {
        return;
      }

      if (event.data.event === "PreviewLoaded") {
        setLatestVersion(event.data.contentVersion);
      }

      if (event.data.event === "PreviewError") {
        updateErrors(event.data.errors);
      }
    }

    window.addEventListener("message", handleMessage);
    return () => {
      window.removeEventListener("message", handleMessage);
    };
  }, []);

  return (
    <div className={styles.toolbarContainer}>
      <div className={styles.toolbarHstack}>
        <div className={styles.toolbarLeft}>
          <StoryError error={errors.publishing}>
            The last publishing attempt was unsuccessful. {additionalPublishingErrorMessage(errors.publishing?.code)}
          </StoryError>
          <StoryError error={errors.preview}>
            The preview is currently unavailable. Please reload the page or contact your administrator.
          </StoryError>
        </div>
        <EditButton url={editUrl} />
      </div>
      {progress && <Progress className={styles.toolbarProgress} value={progress.percent} max={100} />}
      <div style={{ width: "100%", display: "flex", justifyContent: "space-between", alignItems: "end", gap: "md" }}>
        <StoryStatus latest={latestVersion} live={liveVersion} progress={progress} error={errors.publishing} />
        {!progress && <StoryUpdateTime latestVersion={latestVersion} />}
      </div>
    </div>
  );
}

function StoryError({ error, children }: React.PropsWithChildren<{ error: IStoryError | undefined }>): React.JSX.Element | null {
  if (!error) {
    return null;
  }

  return (
    <Tooltip content={error.tooltip} message={error.message || "An error has occurred."}>
      <p className={styles.toolbarError}>{children}</p>
    </Tooltip>
  );
}

function additionalPublishingErrorMessage(code?: string): React.JSX.Element | null {
  if (code?.startsWith("BILLING_")) {
    return (
      <>
        The Shorthand workspace owner should finalise the billing details by completing the workspace activation process in order to
        publish this story.
      </>
    );
  } else if (code?.startsWith("INSUFFICIENT_CREDIT")) {
    return <>The Shorthand workspace owner should purchase additional story credit in order to publish this story.</>;
  }

  return null;
}
