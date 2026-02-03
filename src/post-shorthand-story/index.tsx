import * as React from "react";
import { createRoot } from "react-dom/client";

import { PostEditorToolBar } from "./components/PostEditorToolBar";
import { PHPStoryState, StoryStateProvider } from "./hooks/useStoryState";

declare global {
  interface Window {
    Shorthand: IShorthandWordPressAPI;
  }
}

interface IShorthandWordPressAPI {
  WordPress: {
    restApiUrl: string;
    ajaxApiUrl: string;
    pluginFilesUrl: string;
    ui: {
      createPostEditorToolBar?: (
        container: HTMLDivElement,
        postId: number,
        editUrl: string,
        initialState: PHPStoryState,
        wpNonce: string
      ) => void;
    };
  };
}

function initShorthand(): void {
  const defaults = { WordPress: { restApiUrl: "", ajaxApiUrl: "", pluginFilesUrl: "", ui: {} } };
  if (!window.hasOwnProperty("Shorthand") || !window.Shorthand) {
    window.Shorthand = defaults;
  } else if (!window.Shorthand.hasOwnProperty("WordPress") || !window.Shorthand.WordPress) {
    window.Shorthand.WordPress = defaults.WordPress;
  } else if (!window.Shorthand.WordPress.hasOwnProperty("ui") || !window.Shorthand.WordPress.ui) {
    window.Shorthand.WordPress.ui = {};
  }
}

export function initPostEditor(): void {
  initShorthand();

  if (!window.Shorthand.WordPress.ui.hasOwnProperty("createPostEditorToolBar")) {
    window.Shorthand.WordPress.ui.createPostEditorToolBar = createPostEditorToolBar;
  }
}

function createPostEditorToolBar(
  container: HTMLDivElement,
  postId: number,
  editUrl: string,
  initialState: PHPStoryState,
  wpNonce: string
): void {
  const root = createRoot(container);

  root.render(
    <StoryStateProvider postId={postId} wpNonce={wpNonce} initialState={initialState}>
      <PostEditorToolBar editUrl={editUrl} />
    </StoryStateProvider>
  );
}
