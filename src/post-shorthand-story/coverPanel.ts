/*
 * Drives the Featured image box of a story post.
 *
 * PHP renders the box with the Shorthand cover the last publish saw under
 * the first tab and core's featured image under the second. One request
 * after mount replaces the recorded cover with what Shorthand reports now.
 * "Use story cover now" imports it at once. Kept apart from `useStoryState`,
 * which polls during a pull; the cover needs one request per editor load.
 *
 * Core replaces the whole box when the author sets or removes a featured
 * image, so every handler is delegated from the document and the chosen
 * tab is re-applied after each replacement.
 */

interface ICover {
  id: string;
  url: string;
  name: string;
  width: number;
  height: number;
}

interface ICoverState {
  cover: ICover | null;
  state: string;
  message: string;
  importable: boolean;
  thumbnail?: number;
}

type View = "story" | "featured";

const PANEL_ID = "theshed-cover-panel";
const THUMBNAIL_INPUT_ID = "_thumbnail_id";

/* Core re-renders the featured image box through these admin-ajax actions. */
const THUMBNAIL_ACTIONS = /(^|&)action=(set-post-thumbnail|get-post-thumbnail-html)(&|$)/;

interface IJQueryAjaxSettings {
  data?: unknown;
}

type JQueryLike = (target: Document) => {
  ajaxComplete: (handler: (event: unknown, xhr: unknown, settings: IJQueryAjaxSettings) => void) => void;
};

interface IFeaturedImageApi {
  set: (id: number) => void;
}

let view: View = "story";
let importing = false;

export function refreshStoryCover(postId: number, wpNonce: string): void {
  const run = (): void => {
    void refresh(postId, wpNonce);
  };

  document.addEventListener("click", event => {
    const target = event.target instanceof Element ? event.target : null;
    const panel = target?.closest<HTMLElement>(`#${PANEL_ID}`);
    if (!target || !panel) {
      return;
    }

    const tab = target.closest<HTMLElement>("[role=tab][data-theshed-view]");
    if (tab) {
      show(panel, tab.dataset.theshedView as View);
      return;
    }

    const button = target.closest<HTMLButtonElement>(".theshed-cover-panel__import");
    if (button) {
      void importCover(panel, button, postId, wpNonce);
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run, { once: true });
  } else {
    run();
  }

  const jQuery = (window as unknown as { jQuery?: JQueryLike }).jQuery;
  if (typeof jQuery !== "function") {
    return;
  }
  jQuery(document).ajaxComplete((_event, _xhr, settings) => {
    if (!THUMBNAIL_ACTIONS.test(String(settings?.data ?? ""))) {
      return;
    }
    /* A core re-render follows the author's own change, unless we asked for it. */
    const panel = document.getElementById(PANEL_ID);
    if (panel) {
      show(panel, importing ? "story" : "featured");
    }
    importing = false;
    run();
  });
}

function show(panel: HTMLElement, wanted: View): void {
  view = wanted;
  panel.dataset.view = wanted;
  panel.querySelectorAll<HTMLElement>("[data-theshed-view]").forEach(element => {
    const matches = element.dataset.theshedView === wanted;
    if (element.getAttribute("role") === "tab") {
      element.setAttribute("aria-selected", String(matches));
    } else {
      element.hidden = !matches;
    }
  });
}

/* The form holds the author's unsaved choice; -1 means none. */
function formThumbnail(): number | null {
  const input = document.getElementById(THUMBNAIL_INPUT_ID) as HTMLInputElement | null;
  if (!input) {
    return null;
  }
  return Math.max(0, parseInt(input.value, 10) || 0);
}

async function refresh(postId: number, wpNonce: string): Promise<void> {
  const panel = document.getElementById(PANEL_ID);
  if (!panel) {
    return;
  }

  try {
    const url = new URL(window.Shorthand.WordPress.ajaxApiUrl);
    url.searchParams.set("_ajax_nonce", wpNonce);
    url.searchParams.set("action", "shorthand_get_story_cover");
    url.searchParams.set("post", postId.toString());
    const thumbnail = formThumbnail();
    if (thumbnail !== null) {
      url.searchParams.set("thumbnail", thumbnail.toString());
    }

    const response = await fetch(url);
    if (!response.ok) {
      unavailable(panel);
      return;
    }

    const { success, data } = (await response.json()) as { success: boolean; data?: ICoverState };
    if (!success || !data) {
      unavailable(panel);
      return;
    }

    paint(panel, data);
  } catch (err) {
    console.error(`error: could not refresh the story cover: ${err}`);
    unavailable(panel);
  }
}

async function importCover(
  panel: HTMLElement,
  button: HTMLButtonElement,
  postId: number,
  wpNonce: string
): Promise<void> {
  if (importing) {
    return;
  }
  importing = true;
  button.disabled = true;
  message(panel, "Importing the story cover…");

  try {
    const body = new URLSearchParams({
      _ajax_nonce: wpNonce,
      action: "shorthand_import_story_cover",
      post: postId.toString(),
    });
    const response = await fetch(window.Shorthand.WordPress.ajaxApiUrl, { method: "POST", body });
    const { success, data } = (await response.json()) as {
      success: boolean;
      data?: ICoverState | { message?: string }[];
    };

    if (!success || !data || Array.isArray(data)) {
      const reason = Array.isArray(data) ? data[0]?.message : undefined;
      message(panel, reason ?? "The story cover could not be imported.");
      button.disabled = false;
      importing = false;
      return;
    }

    paint(panel, data);
    rerenderFeatured(data.thumbnail ?? 0);
  } catch (err) {
    console.error(`error: could not import the story cover: ${err}`);
    message(panel, "The story cover could not be imported.");
    button.disabled = false;
    importing = false;
  }
}

/*
 * Lets core redraw its half of the box for the new featured image; that
 * also updates the form field a save would otherwise submit. Without the
 * media API, the form field is set by hand.
 */
function rerenderFeatured(thumbnail: number): void {
  const api = (window as unknown as { wp?: { media?: { featuredImage?: IFeaturedImageApi } } }).wp?.media
    ?.featuredImage;
  if (api && thumbnail) {
    api.set(thumbnail);
    return;
  }

  importing = false;
  const input = document.getElementById(THUMBNAIL_INPUT_ID) as HTMLInputElement | null;
  if (input && thumbnail) {
    input.value = thumbnail.toString();
  }
}

function paint(panel: HTMLElement, { cover, state, message: text, importable }: ICoverState): void {
  const image = panel.querySelector<HTMLImageElement>("img.theshed-cover-panel__image");
  const button = panel.querySelector<HTMLButtonElement>(".theshed-cover-panel__import");

  panel.dataset.state = state;

  if (image) {
    image.hidden = !cover;
    image.src = cover ? cover.url : "";
    image.alt = cover ? cover.name : "";
  }
  if (button) {
    button.hidden = !importable;
    button.disabled = false;
  }
  message(panel, text);
  show(panel, view);
}

function message(panel: HTMLElement, text: string): void {
  const element = panel.querySelector<HTMLElement>(".theshed-cover-panel__message");
  if (element) {
    element.textContent = text;
  }
}

/* Leave a recorded cover alone; only an unchecked panel needs to say so. */
function unavailable(panel: HTMLElement): void {
  if (panel.dataset.state !== "unknown") {
    return;
  }
  message(panel, "The story cover could not be checked.");
}
