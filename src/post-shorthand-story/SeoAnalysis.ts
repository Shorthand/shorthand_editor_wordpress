import { getPreviewContent, initPreviewContent, subscribeToPreviewContent } from "./PreviewContent";

interface IYoastApp {
  registerPlugin(name: string, options: { status: "ready" }): void;
  registerModification(
    modification: "content",
    callback: (content: string) => string,
    pluginName: string,
    priority: number
  ): void;
  refresh(): void;
}

interface IJQueryEventTarget {
  on(eventName: string, listener: () => void): void;
}

interface IWordPressHooks {
  addAction(actionName: string, namespace: string, callback: () => void, priority?: number): void;
  addFilter(filterName: string, namespace: string, callback: (content: string) => string, priority?: number): void;
}

interface IRankMathEditor {
  refresh(type: "content"): void;
}

declare global {
  interface Window {
    YoastSEO?: {
      app?: IYoastApp;
    };
    jQuery?: (target: Window) => IJQueryEventTarget;
    wp?: {
      hooks?: IWordPressHooks;
    };
    rankMathEditor?: IRankMathEditor;
  }
}

const SEO_ANALYSIS_NAMESPACE = "shorthand-preview-content";

let isInitialised = false;
let registeredYoastApp: IYoastApp | null = null;
let isRankMathRegistered = false;

export function initSeoAnalysis(): void {
  if (isInitialised) {
    return;
  }

  isInitialised = true;
  initPreviewContent();
  subscribeToPreviewContent(refreshYoast);
  subscribeToPreviewContent(refreshRankMath);
  registerYoastWhenReady();
  registerRankMathWhenReady();
}

function registerYoastWhenReady(): void {
  if (registerYoastFromWindow()) {
    return;
  }

  window.jQuery?.(window).on("YoastSEO:ready", registerYoastFromWindow);
  window.addEventListener("load", registerYoastFromWindow, { once: true });
}

function registerYoastFromWindow(): boolean {
  const app = window.YoastSEO?.app;
  if (!app) {
    return false;
  }

  if (registeredYoastApp === app) {
    return true;
  }

  registeredYoastApp = app;
  app.registerPlugin(SEO_ANALYSIS_NAMESPACE, { status: "ready" });
  app.registerModification("content", getContentForAnalysis, SEO_ANALYSIS_NAMESPACE, 10);

  if (getPreviewContent() !== null) {
    app.refresh();
  }

  return true;
}

function getContentForAnalysis(content: string): string {
  return getPreviewContent() ?? content;
}

function refreshYoast(): void {
  window.YoastSEO?.app?.refresh();
}

function registerRankMathWhenReady(): void {
  if (registerRankMathFromWindow()) {
    return;
  }

  window.addEventListener("load", registerRankMathFromWindow, { once: true });
}

function registerRankMathFromWindow(): boolean {
  const hooks = window.wp?.hooks;
  if (!hooks || isRankMathRegistered) {
    return isRankMathRegistered;
  }

  isRankMathRegistered = true;
  hooks.addFilter("rank_math_content", SEO_ANALYSIS_NAMESPACE, getContentForAnalysis, 10);
  hooks.addAction("rank_math_loaded", SEO_ANALYSIS_NAMESPACE, refreshRankMath, 20);

  if (getPreviewContent() !== null) {
    refreshRankMath();
  }

  return true;
}

function refreshRankMath(): void {
  if (getPreviewContent() === null) {
    return;
  }

  window.rankMathEditor?.refresh("content");
}
