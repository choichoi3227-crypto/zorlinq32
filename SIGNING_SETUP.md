# Windows 코드사이닝 설정 (SignPath.io 무료 오픈소스 플랜)

`build-and-sign.yml`은 아래 순서를 **모두 사람이 먼저 해줘야** 정상 동작합니다.
자동으로 승인/발급되는 단계는 없습니다 (CA 심사는 본질적으로 수동 절차입니다).

## 1. SignPath 프로젝트 신청 (사람이 함)
1. https://signpath.io/product/open-source 방문
2. GitHub 계정으로 로그인, `choichoi3227-crypto/zorlinq32` 리포지토리 연결
3. 심사 대기 (보통 1~3주). 리포가 public이고 실제 코드가 있어야 승인 가능성이 높습니다.

## 2. 승인 후 SignPath 대시보드에서 발급받는 값들
승인되면 아래 값들을 SignPath 대시보드 > Project Settings 에서 확인할 수 있습니다.

| 값 | 워크플로 내 사용처 |
|---|---|
| Organization ID | `secrets.SIGNPATH_ORGANIZATION_ID` |
| API Token | `secrets.SIGNPATH_API_TOKEN` |
| Project Slug | 워크플로의 `project-slug` (현재 `zorlinq32`로 가정, 실제 슬러그로 수정 필요) |
| Signing Policy Slug | 워크플로의 `signing-policy-slug` (현재 `release-signing`으로 가정, SignPath에서 만든 이름으로 수정 필요) |
| Artifact Configuration Slug | 워크플로의 `artifact-configuration-slug` (SignPath 프로젝트에서 "Windows EXE (Authenticode)" 타입으로 하나 만들어야 함, 현재 `windows-exe`로 가정) |

## 3. GitHub Secrets 등록
리포지토리 > Settings > Secrets and variables > Actions 에서:
- `SIGNPATH_API_TOKEN`
- `SIGNPATH_ORGANIZATION_ID`

를 등록합니다.

## 4. electron-builder 설정 확인
현재 `package.json`의 `build.win.signAndEditExecutable`이 `false`로 되어 있는데,
이건 "electron-builder가 서명을 시도하지 않는다"는 뜻입니다. 이 워크플로는
electron-builder 바깥에서 SignPath로 별도 서명하는 방식이라 이 값은 그대로 둬도 됩니다.

## 5. 처음 태그 릴리즈
설정이 끝나면:
```bash
git tag v0.1.1
git push origin v0.1.1
```
을 실행하면 워크플로가 빌드 → SignPath 서명 → GitHub Release 첨부까지 자동으로 진행됩니다.

## 주의
- 위 표의 project-slug / signing-policy-slug / artifact-configuration-slug 값은
  실제 SignPath 승인 후 대시보드에 나오는 값으로 **반드시 교체**해야 합니다.
  지금 yml에 들어있는 값은 추정치이며, 승인 전에는 확인할 방법이 없습니다.
- SignPath 무료 플랜은 오픈소스 프로젝트 대상입니다. 리포가 비공개로 전환되면
  플랜 조건을 다시 확인해야 합니다.
