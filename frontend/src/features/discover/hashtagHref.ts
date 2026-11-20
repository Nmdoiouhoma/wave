/** Hashtag page (W-43b): `GET /hashtags/{name}`, lowercase, without `#`. */
export const hashtagHref = (name: string) => `/hashtags/${encodeURIComponent(name)}`;
