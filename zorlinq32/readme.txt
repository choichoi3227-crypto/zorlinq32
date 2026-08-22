=== Zorlinq32 ===

AI 글쓰기와 WordPress 관리 도구를 제공합니다.

== 이미지 생성 ==

이미지 생성은 UseAPI.net의 Google Flow REST API를 통해 서버 백그라운드에서 실행됩니다. UseAPI API 토큰과 UseAPI에 사전 연결한 Google Flow 계정이 필요합니다. GCP ID/Secret은 사용하지 않습니다.

지원 모델: Nano Banana 2 Lite (`nano-banana-2-lite`), Nano Banana 2 (`nano-banana-2`), Nano Banana Pro (`nano-banana-pro`). 생성된 이미지는 WordPress 미디어 라이브러리에 저장되며, 이미지 안의 제목 텍스트도 Google Flow 프롬프트에서 렌더링합니다.

== AI 글쓰기 검색 ==

Cloudflare Worker URL과 Shared Secret은 AI 글쓰기 검색 그라운딩에만 사용됩니다. 이미지 생성 또는 이미지 키워드 조사 요청에는 사용되지 않습니다.
