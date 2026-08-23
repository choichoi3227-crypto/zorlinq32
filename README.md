# Zorlinq32

Zorlinq32는 애널리틱스, SEO, 애드센스 보호, 캐싱, 보안, AI 글쓰기 보조 기능을 포함한 워드프레스 플러그인과 Windows 데스크톱 관리 앱을 함께 제공합니다.

## Windows 데스크톱 앱

Electron 기반 `Zorlinq32 Desktop` 앱은 다음 기능을 목표로 구성되어 있습니다.

- 등록한 워드프레스 링크, 사용자명, 애플리케이션 비밀번호를 이용한 REST API 연결 테스트, 최근 글 조회, 글 작성.
- 앱 내부 브라우저(`webview`)에서 워드프레스 관리자 화면 열기.
- 별도 UI에서 사이트, 플러그인, 테마 관리를 시작할 수 있는 REST API 호출 기반 관리 화면.
- 앱 패키지에 `zorlinq32` 워드프레스 플러그인 폴더를 함께 포함하고, 앱에서 내장 플러그인 zip을 생성.
- 원격 설정 페이지에서 Cloudflare Worker URL, Worker Bearer Token, Gemini API Key를 저장.
- 저장되는 워드프레스 애플리케이션 비밀번호, Worker Token, Gemini API Key는 Electron `safeStorage`가 가능한 환경에서 OS 암호화 저장소로 보호.
- Gemini API를 이용한 한국어 워드프레스 글 초안 생성과 Cloudflare Worker 기반 원격 글쓰기 요청.
- 최초 1회 `Flow 로그인` 버튼으로 사용자의 기본 브라우저에서 Google Flow를 열고, 연동 해제 전까지 앱 설정에 연동 상태 유지.
- 이미지 생성 시 사용자의 기본 브라우저로 Google Flow를 열고 프롬프트를 URL 파라미터로 전달.

> 참고: Google Flow의 로그인/생성 화면을 완전 자동 조작하는 기능은 서비스 정책, 브라우저 보안, 사용자 동의 범위에 따라 제한될 수 있습니다. 현재 구현은 사용자의 로컬 브라우저 세션을 열고 프롬프트 전달을 보조하는 안전한 형태입니다.

## 개발 실행

```bash
npm install
npm start
```

## Windows EXE 만들기

Windows 또는 Wine 구성이 준비된 CI/빌드 머신에서 다음을 실행하면 휴대용 Windows EXE가 생성됩니다.

```bash
npm install
npm run package:win
```

빌드 결과는 `dist/` 폴더에 생성됩니다. 기본 명령은 `Zorlinq32 Desktop *.exe` 휴대용 실행 파일을 만들며, 설치형 NSIS 인스톨러가 필요하면 `npm run package:win:installer`를 실행하세요.

## EXE를 바로 만들 수 없는 환경에서 zip 만들기

현재 환경에 Windows 빌드 도구가 없으면 소스 zip을 만든 뒤 Windows PC에서 위 명령을 실행하세요.

```bash
npm run package:zip
```

생성된 `dist/zorlinq32-desktop-source.zip`을 Windows PC로 옮긴 뒤 압축을 풀고 `npm install`, `npm run package:win`을 실행하면 EXE 실행 파일을 만들 수 있습니다.
