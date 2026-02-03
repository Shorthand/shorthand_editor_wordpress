import prettyms from "pretty-ms";
import * as React from "react";

import styles from "./StoryUpdateTime.module.scss";

interface IStoryUpdateTimeProps {
  latestVersion: number | null;
}

/**
 * Displays the time since the story was last updated.
 * @param {IStoryUpdateTimeProps} props - The props for the component.
 * @returns {React.JSX.Element} The rendered component.
 */
export function StoryUpdateTime({ latestVersion }: IStoryUpdateTimeProps): React.JSX.Element | null {
  const [sinceUpdate, setSinceUpdate] = React.useState(0);
  const sinceUpdateTime = sinceUpdate < 60000 ? "0 minutes" : prettyms(sinceUpdate, { compact: true });

  React.useEffect(() => {
    const updated = new Date();
    function updateClock(): void {
      setSinceUpdate(Date.now() - updated.getTime());
    }

    const interval = setInterval(updateClock, 60000);
    updateClock();
    return () => clearInterval(interval);
  }, []);

  if (latestVersion === null) {
    return null;
  }

  return <p className={styles.storyTimeLastUpdated}>Last updated {sinceUpdateTime} ago</p>;
}
