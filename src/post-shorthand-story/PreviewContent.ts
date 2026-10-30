type PreviewLoadedMessage = {
  event: "PreviewLoaded";
};

type PreviewContentListener = (content: string) => void;

let previewContent: string | null = null;
let isInitialised = false;
const listeners = new Set<PreviewContentListener>();

export function initPreviewContent(): void {
  if (isInitialised) {
    return;
  }

  isInitialised = true;
  window.addEventListener("message", handlePreviewMessage);
  window.addEventListener("DOMContentLoaded", capturePreviewContent, { once: true });
}

export function getPreviewContent(): string | null {
  return previewContent;
}

export function subscribeToPreviewContent(listener: PreviewContentListener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

function handlePreviewMessage(event: MessageEvent<unknown>): void {
  if (!isPreviewLoadedMessage(event.data) || event.origin !== window.location.origin) {
    return;
  }

  const iframe = getPreviewIframe();
  if (!iframe || event.source !== iframe.contentWindow) {
    return;
  }

  capturePreviewContent();
}

function capturePreviewContent(): void {
  const iframe = getPreviewIframe();
  const content = iframe?.contentDocument?.body?.innerHTML;

  if (typeof content !== "string" || content === previewContent) {
    return;
  }

  previewContent = content;
  listeners.forEach(listener => listener(content));
}

function getPreviewIframe(): HTMLIFrameElement | null {
  const iframe = document.getElementById("preview-iframe");

  return iframe instanceof HTMLIFrameElement ? iframe : null;
}

function isPreviewLoadedMessage(data: unknown): data is PreviewLoadedMessage {
  return typeof data === "object" && data !== null && "event" in data && data.event === "PreviewLoaded";
}
