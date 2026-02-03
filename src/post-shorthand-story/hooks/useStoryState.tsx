import * as React from "react";

/* The story state exposed to React components */
export interface IStoryState {
  liveVersion: number | null;
  errors: IStoryErrors;
  progress: IStoryProgress | null;
  updateErrors: (errors: PHPErrors | undefined) => void;
}

export interface IStoryErrors {
  publishing?: IStoryError;
  preview?: IStoryError;
}

export interface IStoryError {
  code?: string;
  message?: string;
  tooltip?: string;
  phpError: PHPErrorItem[];
}

export interface IStoryProgress {
  percent: number;
  status: string;
}

interface IStoryStateProviderProps {
  postId: number;
  wpNonce: string;
  initialState: PHPStoryState;
}

/** Provider component for story version, progress and error state */
export function StoryStateProvider({
  postId,
  wpNonce,
  initialState,
  children,
}: React.PropsWithChildren<IStoryStateProviderProps>): JSX.Element {
  const [liveVersion, setLiveVersion] = React.useState(initialState.liveVersion);
  const [progress, setProgress] = React.useState(initialState.progress);
  const [errors, setErrors] = React.useState(() => applyErrors({}, initialState.errors));

  const refreshTimerRef = React.useRef<number>(0);

  React.useEffect(() => {
    let percent = 0;
    let timeout = 2000;

    refreshProgress();
    return () => {
      if (refreshTimerRef.current) {
        clearTimeout(refreshTimerRef.current);
        refreshTimerRef.current = 0;
      }
    };

    async function refreshProgress(): Promise<void> {
      try {
        const url = new URL(window.Shorthand.WordPress.ajaxApiUrl);
        url.searchParams.set("_ajax_nonce", wpNonce);
        url.searchParams.set("action", "shorthand_get_story_state");
        url.searchParams.set("post", postId.toString());

        const response = await fetch(url);

        if (!response.ok) {
          /* stop polling if there was an error */
          return;
        }

        const { data } = await response.json();
        const { liveVersion = null, progress = null, errors } = data;

        setLiveVersion(liveVersion);
        setErrors(current => applyErrors(current, errors));
        setProgress(progress);

        if (!data.progress) {
          return;
        }

        refreshTimerRef.current = setTimeout(refreshProgress, timeout) as unknown as number;
        if (percent === data.progress.percent) {
          timeout = Math.min(timeout * 1.5, 15000);
        } else {
          timeout = 2000;
        }
        percent = data.progress.percent;
      } catch (err) {
        console.error(`error: could not refresh story progress: ${err}`);
      }
    }
  }, [postId, wpNonce]);

  const updateErrors = React.useCallback((errors: PHPErrors | undefined) => {
    setErrors(current => applyErrors(current, errors));
  }, []);

  const context = React.useMemo(
    () => ({ errors, progress, liveVersion, updateErrors }),
    [errors, progress, liveVersion, updateErrors]
  );
  return <StoryStateContext.Provider value={context}>{children}</StoryStateContext.Provider>;
}

/** Consumer hook for story state */
export function useStoryState(): IStoryState {
  return React.useContext(StoryStateContext);
}

export const StoryStateContext = React.createContext<IStoryState>({
  liveVersion: null,
  errors: {},
  progress: null,
  updateErrors: () => {},
});

/* The story state injected from PHP either in the initial HTML or via AJAX */
export interface PHPStoryState {
  liveVersion: number | null;
  errors: PHPErrors;
  progress: IStoryProgress | null;
}

interface PHPErrors {
  publishing?: PHPErrorItem[] | null;
  preview?: PHPErrorItem[] | null;
}

interface PHPErrorItem {
  code: string;
  message: string;
  data?: number | boolean | string;
}

function processErrorList(errors?: PHPErrorItem[]): IStoryError | undefined {
  if (!errors || errors.length === 0) {
    return undefined;
  }

  const prettyError = errors.find(e => e.code === "pretty");
  const codeError = errors.find(e => e.code === "code");

  return {
    code: codeError?.data?.toString(),
    message: prettyError?.message,
    tooltip: errors
      .filter(e => e.code !== "pretty")
      .map(error => error.message)
      .join(" "),
    phpError: errors,
  };
}

function applyErrors(current: IStoryErrors, rawErrors: PHPErrors = {}): IStoryErrors {
  const update = { ...current };
  for (const key in rawErrors) {
    const errors = rawErrors[key as keyof PHPErrors];
    if (errors === null) {
      delete update[key as keyof IStoryErrors];
    } else if (errors) {
      update[key as keyof IStoryErrors] = processErrorList(errors);
    }
  }
  return update;
}
