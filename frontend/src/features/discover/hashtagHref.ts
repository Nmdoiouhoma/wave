/** Hashtag search lands on the W-41 search page (`#name`). */
export const hashtagHref = (name: string) => `/discover?q=${encodeURIComponent(`#${name}`)}`;
