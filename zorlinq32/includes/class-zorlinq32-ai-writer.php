<?php
/**
 * AI 글쓰기 · AI 썸네일 생성 모듈.
 *
 * 포함 기능:
 * 1. Gemini API 기반 블로그 글 자동 생성 (SEO 제목/메타설명/슬러그/포커스 키워드 포함)
 * 2. 멀티 스키마 마크업 자동 생성 (Article / FAQ / Product Review)
 * 3. AI 썸네일 이미지 생성 — 1순위 헤드리스 브라우저(HTML/CSS 카드 스크린샷),
 *    2순위 retired image provider.ai 무료 공개 엔드포인트(전 스타일 nanobanana(Gemini
 *    이미지 모델, Nano Banana)를 기본 모델로 사용하고, nanobanana 호출이
 *    모두 실패했을 때만 flux(FLUX.1 schnell)로 폴백). 5개 스타일(포스터/
 *    미니멀/사실적 사진/타이포그래피/브랜딩) 지원.
 *    ⚠️ 2026-08 개편: 기존 "자체 배포 Cloudflare Worker → AI Horde" 체인을 걷어내고
 *    retired image provider.ai로 전면 교체했다. Worker 배포도, 계정 등록도 전혀 필요 없다
 *    (계정 로그인 자동화 방식은 서비스 약관·보안 문제로 채택하지 않음). API 키는
 *    선택 사항이며, 등록하면 요청 한도가 늘어난다.
 *    ⚠️ 2026-08-09 수정: 한글 텍스트 렌더링 강점 때문에 qwen-image를 썼었으나,
 *    이 모델이 negative 지시를 사실상 무시하는 아키텍처라(avoid 절로 지시하는
 *    "텍스트/인물 금지"를 못 지킴) 무관한 인물·깨진 텍스트가 나오는 오작동의
 *    원인이었다. 전 스타일에서 qwen-image를 제외하고 flux/nanobanana만 사용한다.
 *    ⚠️ 2026-08(5차) 수정: nanobanana를 전 스타일 기본(1순위) 모델로 승격했다.
 *    nanobanana는 포스터/썸네일 맥락에서 명시적으로 금지되지 않은 인물을
 *    자체적으로 채워 넣는 경향이 있었는데, 이는 모델을 바꾸는 대신
 *    reinforce_prompt_for_nanobanana()가 매 호출마다 "사람이 등장하지 않는
 *    순수 오브젝트 상업 디자인 자산" 프레이밍 문장을 프롬프트 앞에 추가하고,
 *    실패 시 시드를 바꿔 최대 2회 재시도하는 방식으로 대응한다.
 * 4. 자체 썸네일 템플릿(배경 이미지 + 제목/부제목 위치 지정) 관리
 * 5. Gemini API 키 다건 등록 관리 — 요청 한도 도달 시 자동으로 다음 키로 전환
 *
 * 참고:
 *  - 썸네일 이미지 생성은 retired image provider.ai(https://gen.retired_image_provider.ai/image/{prompt})의
 *    엔드포인트를 사용합니다(2026-08(6차) 개편: 구 엔드포인트 image.retired_image_provider.ai에서
 *    이전). 스타일과 무관하게 nanobanana(Gemini 이미지 모델)를 기본으로 사용하고,
 *    실패 시에만 flux(FLUX.1 schnell)로 자동 폴백됩니다. 신규 API는 공식 문서상
 *    API 키 없이는 요청이 거부될 수 있으므로, 설정 화면에서 무료 키(enter.retired_image_provider.ai)
 *    등록을 권장합니다.
 *  - FLUX는 SDXL 계열과 달리 (word:1.4) 형식의 가중치 문법과 negative_prompt 파라미터를
 *    지원하지 않으므로, 모든 프롬프트는 자연어 문장형으로 구성되고 회피 요소도 문장 안에
 *    자연어로 녹여 넣는 방식을 사용합니다.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Zorlinq32_AI_Writer {

    private static $instance = null;

    /* 이 플러그인이 수행하는 "글 생성" 관련 전체 작업(본문 생성 + 이어쓰기 등)의 절대 데드라인(unix timestamp, 소수 초 포함).
     * CloudFront 등 CDN의 오리진 응답 타임아웃 안에 무조건 끝내기 위해, 재시도/이어쓰기/대기(sleep)를 포함한
     * 모든 단계가 이 데드라인을 넘기지 않도록 강제한다. AJAX 요청 진입 시 set_request_deadline()으로 시작한다. */
    private $request_deadline = null;

    /** 전체 작업 예산(초). 이 시간을 넘기면 재시도/이어쓰기를 즉시 중단하고 그때까지의 결과로 마무리한다. */
    const TOTAL_BUDGET_SECONDS = 55; // CloudFront 등 오리진 타임아웃보다 여유 있게 짧게(대부분 60초 근방이므로 55초로 설정)

    private function set_request_deadline( $budget_seconds = null ) {
        $budget = ( null === $budget_seconds ) ? self::TOTAL_BUDGET_SECONDS : (float) $budget_seconds;
        $this->request_deadline = microtime( true ) + $budget;
    }

    /** 데드라인까지 남은 시간(초). 데드라인이 설정 안 된 경우(레거시 호출 등) 넉넉한 기본값을 준다. */
    private function time_left() {
        if ( null === $this->request_deadline ) return self::TOTAL_BUDGET_SECONDS;
        return $this->request_deadline - microtime( true );
    }

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    /* zorlinq32.php의 공통 모듈 부트스트랩 루프($module_class::instance())와 호환되도록 별칭 제공 */
    public static function instance() {
        return self::get_instance();
    }

    private function __construct() {
        add_action( 'add_meta_boxes',        [ $this, 'register_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // 메뉴 등록은 Zorlinq32_Admin(관리 메인 메뉴)에서 서브메뉴로 함께 처리합니다.
        add_action( 'init',                  [ $this, 'register_seo_post_meta' ] );
        add_action( 'save_post',             [ $this, 'save_seo_meta_fields' ] );

        // API 키(제미나이) 등록/추가등록/삭제 — 입력 즉시 저장되는 AJAX 방식
        add_action( 'wp_ajax_zorlinq32_ai_add_gemini_key',    [ $this, 'ajax_add_gemini_key' ] );
        add_action( 'wp_ajax_zorlinq32_ai_delete_gemini_key', [ $this, 'ajax_delete_gemini_key' ] );
        add_action( 'wp_ajax_zorlinq32_ai_save_worker_urls',  [ $this, 'ajax_save_worker_urls' ] );

        // AJAX handlers
        add_action( 'wp_ajax_zorlinq32_ai_generate',            [ $this, 'ajax_generate_content' ] );
        add_action( 'wp_ajax_zorlinq32_ai_expand_content',      [ $this, 'ajax_expand_content' ] );
        add_action( 'wp_ajax_zorlinq32_ai_generate_schema',     [ $this, 'ajax_generate_schema' ] );
        add_action( 'wp_ajax_zorlinq32_ai_save_schema_markup',   [ $this, 'ajax_save_schema_markup' ] );
        add_action( 'wp_ajax_zorlinq32_ai_delete_schema',       [ $this, 'ajax_delete_schema' ] );
        add_action( 'wp_ajax_zorlinq32_ai_save_seo_meta',       [ $this, 'ajax_save_all_seo_meta' ] );
        add_action( 'wp_ajax_zorlinq32_ai_generate_thumbnail',  [ $this, 'ajax_generate_thumbnail' ] );
        add_action( 'wp_ajax_zorlinq32_ai_generate_image_prompt', [ $this, 'ajax_generate_image_prompt' ] );
        add_action( 'wp_ajax_zorlinq32_ai_image_generate', [ $this, 'ajax_google_flow_generate' ] );
        add_action( 'wp_ajax_zorlinq32_ai_save_template',           [ $this, 'ajax_save_template' ] );
        add_action( 'wp_ajax_zorlinq32_ai_delete_template',         [ $this, 'ajax_delete_template' ] );
        add_action( 'wp_ajax_zorlinq32_ai_save_font_path',           [ $this, 'ajax_save_font_path' ] );
        add_action( 'wp_ajax_zorlinq32_ai_upload_template_image',    [ $this, 'ajax_upload_template_image' ] );

        // 2026-08 추가: 커스텀 AI 이미지 스타일 추가/삭제(수정은 저장 핸들러 재사용)
        add_action( 'wp_ajax_zorlinq32_ai_save_custom_style',   [ $this, 'ajax_save_custom_ai_style' ] );
        add_action( 'wp_ajax_zorlinq32_ai_delete_custom_style', [ $this, 'ajax_delete_custom_ai_style' ] );


        add_action( 'wp_head', [ $this, 'insert_schema_markup' ], 99 );
        add_action( 'wp_head', [ $this, 'insert_seo_meta_tags' ], 1 );  // ✅ SEO 메타 자동 출력
    }

    /* ── 포스트 메타 등록 ── */
    public function register_seo_post_meta() {
        $fields = [ '_ai_seo_title', '_ai_meta_desc', '_ai_slug', '_ai_focus_keyword', '_ai_blog_schema_markup' ];
        foreach ( $fields as $key ) {
            register_post_meta( 'post', $key, [
                'show_in_rest'  => true, 'single' => true, 'type' => 'string',
                'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
            ] );
        }
    }

    /* ── 메타박스 ── */
    public function register_metabox() {
        add_meta_box( 'zorlinq32-ai-writer-box', 'AI 글쓰기 · 썸네일', [ $this, 'render_metabox' ], 'post', 'side', 'high' );
    }

    public function render_metabox( $post ) {
        wp_nonce_field( 'zorlinq32_ai_nonce', 'zorlinq32_ai_nonce' );
        $seo_title = get_post_meta( $post->ID, '_ai_seo_title',          true );
        $meta_desc = get_post_meta( $post->ID, '_ai_meta_desc',          true );
        $slug      = get_post_meta( $post->ID, '_ai_slug',               true );
        $focus_kw  = get_post_meta( $post->ID, '_ai_focus_keyword',      true );
        $schema    = get_post_meta( $post->ID, '_ai_blog_schema_markup', true );
        $schemas   = $this->decode_schemas( $schema );
        ?>
        <div id="zorlinq32-ai-writer-container">

            <!-- 탭 헤더 -->
            <div class="zorlinq32-ai-tabs">
                <button type="button" class="zorlinq32-ai-tab active" data-tab="content">AI 글쓰기</button>
                <button type="button" class="zorlinq32-ai-tab" data-tab="thumbnail">AI 썸네일</button>
            </div>

            <!-- AI 글쓰기 탭 -->
            <div class="zorlinq32-ai-tab-content active" data-content="content">
                <div class="zorlinq32-ai-input-group">
                    <label for="zorlinq32-ai-topic">주제 키워드</label>
                    <input type="text" id="zorlinq32-ai-topic" class="zorlinq32-ai-input" placeholder="예: 민생회복지원금" />
                </div>
                <div class="zorlinq32-ai-input-group">
                    <label for="zorlinq32-ai-type">글 유형</label>
                    <select id="zorlinq32-ai-type" class="zorlinq32-ai-select">
                        <option value="informational">정보성</option>
                        <option value="utility">유틸리티</option>
                        <option value="policy_guide">정책·공공</option>
                        <option value="review_comparison">리뷰·비교 (쿠팡파트너스)</option>
                    </select>
                </div>

                <div style="text-align:left;margin-top:6px;">
                <button type="button" id="zorlinq32-ai-generate-btn" class="zorlinq32-ai-button zorlinq32-ai-button--primary">AI 콘텐츠 생성</button>
                </div>
                <div id="zorlinq32-ai-progress" class="zorlinq32-ai-progress" style="display:none;">
                    <div class="zorlinq32-ai-progress-bar">
                        <div class="zorlinq32-ai-progress-fill"></div>
                    </div>
                    <div class="zorlinq32-ai-progress-text">
                        <span class="progress-label">AI 처리 시작 중</span>
                        <span class="progress-percent">0%</span>
                    </div>
                </div>

                <!-- SEO 메타 정보 숨김 필드 (자동 저장용) -->
                <input type="hidden" id="ai_seo_title" name="ai_seo_title" value="<?php echo esc_attr( $seo_title ); ?>" />
                <input type="hidden" id="ai_meta_desc" name="ai_meta_desc" value="<?php echo esc_attr( $meta_desc ); ?>" />
                <input type="hidden" id="ai_slug" name="ai_slug" value="<?php echo esc_attr( $slug ); ?>" />
                <input type="hidden" id="ai_focus_keyword" name="ai_focus_keyword" value="<?php echo esc_attr( $focus_kw ); ?>" />
            </div>

            <!-- AI 썸네일 탭 -->
            <div class="zorlinq32-ai-tab-content" data-content="thumbnail">
                <div class="zorlinq32-ai-input-group">
                    <label for="zorlinq32-ai-thumb-topic">썸네일 주제</label>
                    <input type="text" id="zorlinq32-ai-thumb-topic" class="zorlinq32-ai-input"
                           placeholder="예: 다이어트 방법, 재테크 전략" />
                </div>
                <div class="zorlinq32-ai-input-group">
                    <label for="zorlinq32-ai-thumb-style">이미지 스타일</label>
                    <select id="zorlinq32-ai-thumb-style" class="zorlinq32-ai-select">
                        <optgroup label="AI 이미지 생성">
                            <option value="poster">포스터</option>
                            <option value="minimal">미니멀</option>
                            <option value="photo_realistic">사실적 사진</option>
                            <option value="typography">타이포그래피</option>
                            <option value="branding">브랜딩</option>
                        </optgroup>
                        <?php
                        $_zorlinq32_ai_custom_styles = $this->get_custom_ai_styles_list();
                        if ( ! empty( $_zorlinq32_ai_custom_styles ) ) :
                        ?>
                        <optgroup label="커스텀 AI 스타일">
                            <?php foreach ( $_zorlinq32_ai_custom_styles as $_cs ) : ?>
                                <option value="<?php echo esc_attr( $_cs['id'] ); ?>"><?php echo esc_html( $_cs['name'] ); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php
                        $_zorlinq32_ai_tpls = get_option( 'zorlinq32_ai_thumb_templates', [] );
                        if ( ! empty( $_zorlinq32_ai_tpls ) ) :
                        ?>
                        <optgroup label="자체 썸네일 템플릿">
                            <?php foreach ( $_zorlinq32_ai_tpls as $_ti => $_t ) : ?>
                                <option value="custom_tpl_<?php echo $_ti; ?>"><?php echo esc_html( $_t['name'] ); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <!-- 자체 템플릿 선택 시 나타나는 제목/부제목 입력 영역 -->
                <div id="zorlinq32-ai-custom-tpl-inputs" style="display:none;">
                    <div class="zorlinq32-ai-input-group">
                        <label for="zorlinq32-ai-tpl-title-input">📝 제목</label>
                        <input type="text" id="zorlinq32-ai-tpl-title-input" class="zorlinq32-ai-input" placeholder="썸네일에 표시할 제목" />
                    </div>
                    <div class="zorlinq32-ai-input-group">
                        <label for="zorlinq32-ai-tpl-sub-input">📌 부제목</label>
                        <input type="text" id="zorlinq32-ai-tpl-sub-input" class="zorlinq32-ai-input" placeholder="썸네일에 표시할 부제목" />
                    </div>
                </div>
                <div style="text-align:left;margin-top:6px;">
                <button type="button" id="zorlinq32-ai-thumb-generate-btn" class="zorlinq32-ai-button zorlinq32-ai-button--primary">🖼️ 썸네일 생성</button>
                </div>
                <div id="zorlinq32-ai-thumb-progress" style="display:none;margin-top:10px;text-align:center;padding:12px;background:#f7f9ff;border-radius:8px;">
                    <div class="zorlinq32-ai-spin-loader"></div>
                    <div id="zorlinq32-ai-thumb-progress-text" style="margin:8px 0 0;font-size:12px;color:#555;text-align:center;">⏳ 처리 중...</div>
                </div>
                <div id="zorlinq32-ai-thumb-preview" style="display:none;margin-top:12px;">
                    <img id="zorlinq32-ai-thumb-img" src="" alt="썸네일 미리보기"
                         style="width:100%;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.15);">
                    <p style="font-size:11px;color:#888;margin:6px 0 0;text-align:center;">✅ 대표 이미지 설정 완료</p>
                </div>
                <span id="zorlinq32-ai-thumb-status" style="display:block;margin-top:8px;font-size:12px;min-height:16px;"></span>
            </div>

            <!-- 구분선 -->
            <hr style="border:none;border-top:2px solid #f0f0f0;margin:0;">

            <!-- AI 스키마 마크업 섹션 -->
            <div id="zorlinq32-ai-schema-section" style="padding:16px 20px 20px;">
                <div style="font-size:14px;font-weight:700;color:#262626;margin-bottom:10px;">⭐ AI 스키마 마크업 (멀티 지원)</div>

                <div class="zorlinq32-ai-input-group" style="margin-bottom:8px;">
                    <select id="zorlinq32-ai-schema-type" class="zorlinq32-ai-select">
                        <option value="">스키마 유형 선택</option>
                        <option value="article">기사 (Article)</option>
                        <option value="faq">FAQ</option>
                        <option value="product_review">상품리뷰 (Product Review)</option>
                    </select>
                </div>

                <div style="text-align:left;margin-top:6px;"><button type="button" id="zorlinq32-ai-schema-generate-btn" class="zorlinq32-ai-button zorlinq32-ai-button--primary" style="margin-top:0;">
                    ➕ 스키마 추가 생성
                </button></div>

                <!-- 진행 단계 표시 -->
                <div id="zorlinq32-ai-schema-progress" style="display:none;margin-top:10px;padding:12px;background:#f0f7ff;border-radius:8px;border:1px solid #b3d4f5;">
                    <div id="zorlinq32-ai-schema-step" style="font-size:12px;font-weight:600;color:#4f46e5;">⏳ 스키마 분석 중...</div>
                    <div style="margin-top:6px;height:4px;background:#ddeeff;border-radius:4px;overflow:hidden;">
                        <div id="zorlinq32-ai-schema-progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#00a37d,#4f46e5);border-radius:4px;transition:width 0.6s ease;"></div>
                    </div>
                </div>

                <!-- 적용된 스키마 목록 -->
                <div id="zorlinq32-ai-schema-list" style="margin-top:10px;">
                    <?php if ( ! empty( $schemas ) ) : ?>
                        <?php foreach ( $schemas as $idx => $s ) :
                            $json_str = '';
                            if ( ! empty( $s['json'] ) ) {
                                $json_str = is_string( $s['json'] ) ? $s['json'] : wp_json_encode( $s['json'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
                            } elseif ( ! empty( $s['html'] ) && preg_match( '/<script[^>]*>([\s\S]*?)<\/script>/i', $s['html'], $jm ) ) {
                                $json_str = trim( $jm[1] );
                            }
                        ?>
                        <div class="zorlinq32-ai-schema-item"
                             data-index="<?php echo $idx; ?>"
                             data-type="<?php echo esc_attr( $s['type'] ); ?>"
                             data-json="<?php echo esc_attr( $json_str ); ?>">
                            <div class="zorlinq32-ai-schema-item-header">
                                <span class="zorlinq32-ai-schema-item-label">✅ <?php echo esc_html( strtoupper( $s['type'] ) ); ?> 스키마</span>
                                <div class="zorlinq32-ai-schema-item-actions">
                                    <button type="button" class="zorlinq32-ai-small-btn zorlinq32-ai-btn-edit zorlinq32-ai-schema-edit-single" data-index="<?php echo $idx; ?>">✏️ 편집</button>
                                    <button type="button" class="zorlinq32-ai-small-btn zorlinq32-ai-btn-danger zorlinq32-ai-schema-delete-single" data-index="<?php echo $idx; ?>">🗑 삭제</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $schemas ) ) : ?>
                <button type="button" id="zorlinq32-ai-schema-delete-all-btn" class="zorlinq32-ai-small-btn zorlinq32-ai-btn-danger" style="margin-top:8px;width:100%;">🗑 전체 스키마 삭제</button>
                <?php endif; ?>

                <span id="zorlinq32-ai-schema-status" style="display:block;margin-top:8px;font-size:12px;min-height:16px;"></span>
            </div>

            <!-- 결과 메시지 -->
            <div id="zorlinq32-ai-result" class="zorlinq32-ai-result" style="display:none;margin:0 20px 16px;"></div>

        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════
       스키마 디코드 헬퍼 v4.0 — 완전 재작성
       규칙: json 필드는 항상 "pretty-printed 문자열"로 반환
       DB 저장 형식: [{"type":"article","json":{...객체...}}, ...]
       — json 필드를 객체로 저장하여 이중인코딩 문제 완전 제거
    ══════════════════════════════════════════════════════ */
    private function decode_schemas( $raw ) {
        if ( empty( $raw ) ) return [];

        $str = trim( $raw );
        if ( empty( $str ) ) return [];

        // ── STEP 1: 최외곽 JSON 파싱 ──
        $outer = json_decode( $str, true );

        // ── STEP 2: JSON 배열 형식 (표준 멀티 스키마) ──
        if ( is_array( $outer ) && isset( $outer[0] ) ) {
            $result = [];
            foreach ( $outer as $item ) {
                if ( ! is_array( $item ) || empty( $item['type'] ) ) continue;

                $schema_obj = null;
                // json 필드: 객체(새 형식) or 문자열(구 형식) 둘 다 처리
                if ( isset( $item['json'] ) ) {
                    if ( is_array( $item['json'] ) ) {
                        $schema_obj = $item['json'];
                    } elseif ( is_string( $item['json'] ) && ! empty( $item['json'] ) ) {
                        $parsed = json_decode( $item['json'], true );
                        if ( is_array( $parsed ) ) $schema_obj = $parsed;
                    }
                }
                // 레거시: html 필드 → script 태그에서 추출
                if ( ! $schema_obj && ! empty( $item['html'] ) ) {
                    if ( preg_match( '/<script[^>]*>([\s\S]*?)<\/script>/i', $item['html'], $m ) ) {
                        $parsed = json_decode( trim( $m[1] ), true );
                        if ( is_array( $parsed ) ) $schema_obj = $parsed;
                    }
                }

                if ( $schema_obj ) {
                    $result[] = [
                        'type' => sanitize_key( $item['type'] ),
                        'json' => wp_json_encode( $schema_obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                    ];
                }
            }
            return $result;
        }

        // ── STEP 3: 단일 JSON 객체 (레거시 형식) ──
        if ( is_array( $outer ) && ! empty( $outer['@type'] ) ) {
            $type = strtolower( sanitize_key( $outer['@type'] ) );
            return [ [
                'type' => $type,
                'json' => wp_json_encode( $outer, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            ] ];
        }

        // ── STEP 4: 레거시 — script 태그 포함 문자열 ──
        if ( preg_match( '/<script[^>]*>([\s\S]*?)<\/script>/i', $str, $m ) ) {
            $parsed = json_decode( trim( $m[1] ), true );
            if ( is_array( $parsed ) ) {
                $type = isset( $parsed['@type'] ) ? strtolower( sanitize_key( $parsed['@type'] ) ) : 'schema';
                return [ [
                    'type' => $type,
                    'json' => wp_json_encode( $parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                ] ];
            }
        }

        return [];
    }

    /* ══════════════════════════════════════════════════════
       스키마 저장 헬퍼 v4.0 — 완전 재작성
       DB에 json 필드를 객체(배열)로 저장 → 이중인코딩 제거
       반환값: decode_schemas() 형식 (json은 pretty-string)
    ══════════════════════════════════════════════════════ */
    private function save_schemas( $post_id, array $schemas ) {
        $to_save = [];
        foreach ( $schemas as $s ) {
            if ( empty( $s['type'] ) ) continue;

            // json 필드를 반드시 PHP 배열(객체)로 변환
            $schema_obj = null;
            if ( isset( $s['json'] ) ) {
                if ( is_array( $s['json'] ) ) {
                    $schema_obj = $s['json'];
                } elseif ( is_string( $s['json'] ) && ! empty( $s['json'] ) ) {
                    $parsed = json_decode( $s['json'], true );
                    if ( is_array( $parsed ) ) $schema_obj = $parsed;
                }
            }
            if ( ! $schema_obj ) continue;

            $to_save[] = [
                'type' => sanitize_key( $s['type'] ),
                'json' => $schema_obj,   // ✅ 객체로 저장 (이중인코딩 없음)
            ];
        }

        if ( empty( $to_save ) ) {
            delete_post_meta( $post_id, '_ai_blog_schema_markup' );
        } else {
            $encoded = wp_json_encode( $to_save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            update_post_meta( $post_id, '_ai_blog_schema_markup', $encoded );
        }

        // 반환값은 항상 decode_schemas 형식 (json = pretty string)
        return $this->decode_schemas(
            wp_json_encode( $to_save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );
    }

    /* ── save_post ── */
    public function save_seo_meta_fields( $post_id ) {
        if ( ! isset( $_POST['zorlinq32_ai_nonce'] ) || ! wp_verify_nonce( $_POST['zorlinq32_ai_nonce'], 'zorlinq32_ai_nonce' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        $fields = [ 'ai_seo_title' => '_ai_seo_title', 'ai_meta_desc' => '_ai_meta_desc', 'ai_slug' => '_ai_slug', 'ai_focus_keyword' => '_ai_focus_keyword' ];
        foreach ( $fields as $k => $meta ) {
            if ( isset( $_POST[ $k ] ) ) update_post_meta( $post_id, $meta, sanitize_text_field( $_POST[ $k ] ) );
        }
    }

    /* ── 에셋 ── */
    public function enqueue_assets( $hook ) {
        $allowed_hooks = [
            'post.php',
            'post-new.php',
            'zorlinq32_page_zorlinq32-ai-writer',
            'zorlinq32_page_zorlinq32-ai-thumb-templates',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        // 미디어 업로더 (템플릿 관리 페이지에서 필수)
        wp_enqueue_media();

        wp_enqueue_style( 'zorlinq32-ai-writer', ZORLINQ32_URL . 'assets/css/ai-writer.css', [], ZORLINQ32_VERSION );
        wp_enqueue_script( 'zorlinq32-ai-writer', ZORLINQ32_URL . 'assets/js/ai-writer.js', [ 'jquery' ], ZORLINQ32_VERSION, true );
        global $post;
        $zorlinq32_ai_tpl_data = get_option( 'zorlinq32_ai_thumb_templates', [] );

        // 2026-08 추가: 커스텀 AI 스타일의 id → base 매핑을 JS에 전달.
        // JS는 텍스트 오버레이 레이아웃(getTextConfig)을 계산할 때 커스텀 스타일 id
        // 대신 이 base 값을 사용해, 항상 5개 기본 스타일 중 하나의 검증된 레이아웃으로
        // 폴백되도록 한다(정의되지 않은 스타일로 인한 JS 오류 방지).
        $zorlinq32_ai_custom_style_bases = [];
        foreach ( $this->get_custom_ai_styles_list() as $_cs_item ) {
            if ( ! empty( $_cs_item['id'] ) ) {
                $zorlinq32_ai_custom_style_bases[ $_cs_item['id'] ] = ! empty( $_cs_item['base'] ) ? $_cs_item['base'] : 'poster';
            }
        }

        wp_localize_script( 'zorlinq32-ai-writer', 'zorlinq32AiWriter', [
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'zorlinq32_ai_nonce' ),
            'postId'            => isset( $post->ID ) ? $post->ID : 0,
            'templates'         => array_values( $zorlinq32_ai_tpl_data ),
            'customStyleBases'  => $zorlinq32_ai_custom_style_bases,
        ] );
    }

    /* ── 설정 ── */
    /* 메뉴 등록은 Zorlinq32_Admin::register_menu()에서 서브메뉴로 함께 처리합니다.
       (render_settings_page / render_template_manager 를 콜백으로 등록) */

    public function register_settings() {
        // API 키는 배열(리스트)로 저장되며, 화면에서는 등록/추가등록/삭제 방식(AJAX)으로만 관리합니다.
        register_setting( 'zorlinq32_ai_writer_settings', 'zorlinq32_ai_gemini_api_keys' );
        register_setting( 'zorlinq32_ai_writer_settings', 'zorlinq32_ai_search_worker_url' );
        register_setting( 'zorlinq32_ai_writer_settings', 'zorlinq32_ai_search_worker_secret' );
        // 2026-08 개편: 이미지 생성이 retired image provider.ai로 전환되면서 Cloudflare Worker
        // URL/Secret, AI Horde API 키 설정은 제거했다. 대신 retired image provider 쪽 인증
        // 정책 강화에 대응해 선택적 API 키 필드를 추가한다(미입력 시에도 동작함).

    }

    /* ── AJAX: Gemini API 키 추가 등록 ──
       입력 → [등록] 클릭 시 1개가 목록에 추가되는 방식. 엔터/콤마 구분 파싱이 필요 없습니다.
       이미 등록된 키와 완전히 같은 값이면 중복 등록하지 않습니다. */
    public function ajax_add_gemini_key() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        $key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
        if ( '' === $key ) wp_send_json_error( [ 'message' => 'API 키를 입력해주세요.' ] );

        $keys = $this->get_api_keys();
        if ( in_array( $key, $keys, true ) ) {
            wp_send_json_error( [ 'message' => '이미 등록된 키입니다.' ] );
        }
        $keys[] = $key;
        update_option( 'zorlinq32_ai_gemini_api_keys', $keys );

        wp_send_json_success( [
            'message' => '키가 등록되었습니다. (총 ' . count( $keys ) . '개)',
            'keys'    => $this->mask_api_keys( $keys ),
            'count'   => count( $keys ),
        ] );
    }

    /* ── AJAX: Gemini API 키 삭제 ── */
    public function ajax_delete_gemini_key() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        $keys  = $this->get_api_keys();
        if ( ! isset( $keys[ $index ] ) ) wp_send_json_error( [ 'message' => '존재하지 않는 키입니다.' ] );

        array_splice( $keys, $index, 1 );
        update_option( 'zorlinq32_ai_gemini_api_keys', $keys );

        wp_send_json_success( [
            'message' => '키가 삭제되었습니다. (총 ' . count( $keys ) . '개)',
            'keys'    => $this->mask_api_keys( $keys ),
            'count'   => count( $keys ),
        ] );
    }

    /* ── AJAX: Worker URL / Secret 저장 (검색 그라운딩 전용) ──
       2026-08 개편: 이미지 생성용 Cloudflare Worker URL/Secret과 AI Horde API 키
       필드는 이미지 생성이 retired image provider.ai로 전환되면서 제거했다. 검색 그라운딩용
       Worker 설정과, retired image provider 선택적 API 키가 이 핸들러에 남아있다. */
    public function ajax_save_worker_urls() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        $fields = [
            'search_worker_url'    => 'zorlinq32_ai_search_worker_url',
            'search_worker_secret' => 'zorlinq32_ai_search_worker_secret',
        ];
        foreach ( $fields as $post_key => $option_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_option( $option_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
            }
        }
        wp_send_json_success( [ 'message' => 'Worker 설정이 저장되었습니다.' ] );
    }

    /* ── 등록된 키를 앞 6자·뒤 4자만 보이도록 마스킹해 화면에 전달 ── */
    private function mask_api_keys( array $keys ) {
        $out = [];
        foreach ( $keys as $k ) {
            $len = mb_strlen( $k );
            if ( $len <= 12 ) {
                $out[] = str_repeat( '•', max( 0, $len - 2 ) ) . mb_substr( $k, -2 );
            } else {
                $out[] = mb_substr( $k, 0, 6 ) . str_repeat( '•', 6 ) . mb_substr( $k, -4 );
            }
        }
        return $out;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $search_worker_url = get_option( 'zorlinq32_ai_search_worker_url', '' );
        $search_worker_sec = get_option( 'zorlinq32_ai_search_worker_secret', '' );
        $keys_masked = $this->mask_api_keys( $this->get_api_keys() );
        $nonce = wp_create_nonce( 'zorlinq32_ai_nonce' );
        $flow_config = class_exists( 'Zorlinq32_UseAPI_Google_Flow' ) ? Zorlinq32_UseAPI_Google_Flow::get_config() : [ 'api_token' => '', 'email' => '', 'model' => 'nano-banana-2-lite' ];
        ?>
        <div class="wrap zorlinq32-wrap">
            <?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>
            <div class="zorlinq32-settings-section">
                <h2><span class="dashicons dashicons-google"></span> UseAPI Google Flow 백그라운드 이미지 생성</h2>
                <p class="zorlinq32-help-text">UseAPI API 토큰과 UseAPI에 연결한 전용 Google Flow 계정을 사용합니다. GCP ID/Secret은 사용하지 않습니다.</p>
                <table class="form-table" role="presentation"><tr><th>UseAPI API 토큰</th><td><input type="password" id="zorlinq32-useapi-token" placeholder="변경 시에만 입력" style="width:100%;max-width:520px;" /></td></tr><tr><th>Google Flow 계정 이메일</th><td><input type="email" id="zorlinq32-useapi-email" value="<?php echo esc_attr( $flow_config['email'] ); ?>" style="width:100%;max-width:520px;" /><p class="description">UseAPI에 계정을 하나만 연결했다면 비워 두면 됩니다.</p></td></tr><tr><th>기본 모델</th><td><select id="zorlinq32-useapi-model"><option value="nano-banana-2-lite" <?php selected( $flow_config['model'], 'nano-banana-2-lite' ); ?>>Nano Banana 2 Lite</option><option value="nano-banana-2" <?php selected( $flow_config['model'], 'nano-banana-2' ); ?>>Nano Banana 2</option><option value="nano-banana-pro" <?php selected( $flow_config['model'], 'nano-banana-pro' ); ?>>Nano Banana Pro</option></select></td></tr></table>
                <p><button type="button" class="button button-primary" id="zorlinq32-useapi-save">UseAPI 설정 저장</button><span id="zorlinq32-useapi-msg"></span></p>
            </div>
            <div class="zorlinq32-settings-section">
                <h2>AI 글쓰기 설정</h2>
                <p class="zorlinq32-help-text">Cloudflare Worker는 AI 글쓰기의 검색 그라운딩에만 사용됩니다. 이미지 생성·이미지 조사에는 호출되지 않습니다.</p>
                <table class="form-table" role="presentation"><tr><th>Gemini API 키</th><td><div id="zorlinq32-ai-key-list"><?php foreach ( $keys_masked as $idx => $km ) : ?><div class="zorlinq32-ai-key-row" data-index="<?php echo (int) $idx; ?>"><span><?php echo esc_html( $km ); ?></span> <button type="button" class="button button-small zorlinq32-ai-key-delete" data-index="<?php echo (int) $idx; ?>">삭제</button></div><?php endforeach; ?></div><input type="text" id="zorlinq32-ai-key-input" placeholder="AIzaSy..." /> <button type="button" class="button button-primary" id="zorlinq32-ai-key-register">등록</button><span id="zorlinq32-ai-key-msg"></span></td></tr><tr><th><label for="zorlinq32-ai-search-worker-url">글쓰기 검색 Worker URL</label></th><td><input type="url" id="zorlinq32-ai-search-worker-url" value="<?php echo esc_attr( $search_worker_url ); ?>" style="width:100%;max-width:520px;" /><p class="description">AI 글쓰기 검색 그라운딩 전용 base URL입니다.</p></td></tr><tr><th><label for="zorlinq32-ai-search-worker-secret">검색 Worker Shared Secret</label></th><td><input type="password" id="zorlinq32-ai-search-worker-secret" value="<?php echo esc_attr( $search_worker_sec ); ?>" style="width:100%;max-width:520px;" /></td></tr></table>
                <p><button type="button" class="button button-primary" id="zorlinq32-ai-worker-save">글쓰기 Worker 설정 저장</button><span id="zorlinq32-ai-worker-msg"></span></p>
            </div>
        </div>
        <script>jQuery(function($){var ajaxUrl='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',nonce='<?php echo esc_js( $nonce ); ?>'; $('#zorlinq32-ai-key-register').on('click',function(){$.post(ajaxUrl,{action:'zorlinq32_ai_add_gemini_key',nonce:nonce,api_key:$('#zorlinq32-ai-key-input').val()},function(r){if(r.success)location.reload();else $('#zorlinq32-ai-key-msg').text(r.data.message);});}); $(document).on('click','.zorlinq32-ai-key-delete',function(){$.post(ajaxUrl,{action:'zorlinq32_ai_delete_gemini_key',nonce:nonce,index:$(this).data('index')},function(){location.reload();});}); $('#zorlinq32-useapi-save').on('click',function(){$.post(ajaxUrl,{action:'zorlinq32_useapi_flow_save_config',nonce:nonce,api_token:$('#zorlinq32-useapi-token').val(),email:$('#zorlinq32-useapi-email').val(),model:$('#zorlinq32-useapi-model').val()},function(r){$('#zorlinq32-useapi-msg').text(r.success?'저장되었습니다.':r.data.message);});}); $('#zorlinq32-ai-worker-save').on('click',function(){$.post(ajaxUrl,{action:'zorlinq32_ai_save_worker_urls',nonce:nonce,search_worker_url:$('#zorlinq32-ai-search-worker-url').val(),search_worker_secret:$('#zorlinq32-ai-search-worker-secret').val()},function(r){$('#zorlinq32-ai-worker-msg').text(r.success?'저장되었습니다.':r.data.message);});});});</script>
        <?php
    }

    /* ── Gemini API 키 목록 조회 (배열로 저장됨) ──
       하위호환: 과거 버전(줄바꿈/콤마 구분 문자열)으로 저장된 값이 남아있다면 배열로 자동 변환합니다.
       ※ 2026-06-15 Google 공지 기준: gemini-2.5-flash / gemini-2.5-flash-lite는
          2026년 10월 16일 지원 종료 예정 → gemini-3.1-flash-lite로 교체(전 호출 지점 통일, 무료 티어 RPM 여유 확보) */
    private function get_api_keys() {
        $raw = get_option( 'zorlinq32_ai_gemini_api_keys', [] );

        // 배열(신규 방식)로 이미 저장돼 있으면 그대로 사용
        if ( is_array( $raw ) ) {
            $keys = [];
            foreach ( $raw as $p ) {
                $p = trim( (string) $p );
                if ( $p !== '' && ! in_array( $p, $keys, true ) ) $keys[] = $p;
            }
            return $keys;
        }

        // 레거시(문자열) 저장값 — 콤마·줄바꿈·공백으로 구분된 값을 배열로 변환해 1회성 마이그레이션
        $raw = trim( (string) $raw );
        if ( empty( $raw ) ) return [];
        // 콤마·줄바꿈·공백 어떤 조합으로 구분해도 파싱
        $parts = preg_split( '/[\s,]+/', $raw );
        $keys  = [];
        foreach ( $parts as $p ) {
            $p = trim( $p );
            if ( $p !== '' && ! in_array( $p, $keys, true ) ) $keys[] = $p;
        }
        return $keys;
    }

    private function get_next_api_key( array $keys, $exclude = [] ) {
        $available = array_values( array_diff( $keys, $exclude ) );
        if ( empty( $available ) ) return null;
        // 라운드로빈: transient 기반 인덱스
        $idx_key = 'zorlinq32_ai_key_idx_' . md5( implode( '', $keys ) );
        $idx     = (int) get_transient( $idx_key );
        $idx     = $idx % count( $available );
        set_transient( $idx_key, ( $idx + 1 ) % count( $available ), 3600 );
        return $available[ $idx ];
    }

    /* ── 검색 그라운딩 Worker 기반 URL 유틸 ──
       설정 페이지의 "검색 그라운딩 Worker URL" 입력란에는 이제 Groq 기반
       워커가 아니라 cloud-press(Cloudflare Worker/Pages, GitHub:
       choichoi3227-crypto/cloud-press) 배포 base URL을 입력한다.
       cloud-press는 두 개의 하위 경로를 제공한다:
         - GET  {base}/api/search   → 검색 결과(JSON)
         - POST {base}/api/search → 주제 조사 결과(JSON, research 필드)
       사용자가 base URL만 입력하든, 실수로 끝에 /api/search나
       /api/search까지 붙여서 입력하든 모두 올바르게 동작하도록
       아래에서 항상 base를 정규화한 뒤 필요한 경로를 새로 붙인다. */
    private function normalize_search_worker_base( $worker_url ) {
        $worker_url = trim( (string) $worker_url );
        if ( '' === $worker_url ) return '';
        // 사용자가 실수로 /api/search, /api/search, 혹은 끝에 슬래시까지
        // 입력해도 항상 base(스킴+호스트+경로)만 남긴다.
        $worker_url = preg_replace( '#/api/search/?$#i', '', $worker_url );
        return rtrim( $worker_url, '/' );
    }

    private function search_worker_endpoint( $path ) {
        $base = $this->normalize_search_worker_base( get_option( 'zorlinq32_ai_search_worker_url', '' ) );
        if ( '' === $base ) return '';
        return $base . $path;
    }

    /* ── 검색 그라운딩: cloud-press(Cloudflare Worker/Pages) 검색 API 호출 ──
       Gemini 내장 google_search 툴이 무료 티어에서 429로 막히는 문제를 우회하기 위해,
       검색은 별도 Cloudflare 배포(cloud-press, GET /api/search)가 수행하고 결과
       텍스트를 프롬프트 앞부분에 삽입한다. Worker 미설정 시 빈 문자열을 반환해
       그라운딩 없이 진행한다.
       ⚠️ 2026-08(4차) 개편: 기존에는 Groq 기반 워커에 POST로 { query, max_results,
       country }를 보내고 { summary, results[] } 형태의 응답을 기대했다. 이제
       cloud-press의 GET /api/search?q=...&engine=all 을 호출하고, 응답 형태
       { providers: [ { results: [{title,url,snippet}] } ] } 를 파싱해 기존과
       동일한 그라운딩 블록 텍스트를 조립한다. 즉 이 함수의 반환값과 호출부
       계약은 그대로 유지되므로, 이 함수를 호출하는 다른 코드는 수정할 필요가 없다. */
    private function fetch_search_grounding( $query, $max_results = 8 ) {
        $endpoint = $this->search_worker_endpoint( '/api/search' );
        if ( empty( $endpoint ) ) return '';
        $secret = trim( get_option( 'zorlinq32_ai_search_worker_secret', '' ) );

        // ⚠️ 방어적 안전장치: 호출부가 실수로 매우 긴 문자열(예: 글 전체 내용)을
        // query로 넘기더라도, 여기서 항상 짧은 검색어 길이로 잘라 상대 검색
        // 사이트로의 요청이 과도하게 커지는 것을 막는다.
        $query = mb_substr( trim( (string) $query ), 0, 200, 'UTF-8' );
        if ( '' === $query ) return '';

        // ⚠️ 2026-08 수정: "이미지는 반드시 조사(그라운딩) 내용을 바탕으로 생성되어야
        // 한다"는 요구사항에 따라, 검색 API가 일시적으로 실패해도(타임아웃, 5xx 등)
        // 곧바로 포기하지 않고 최대 2회까지 재시도한다.
        // ⚠️ 2026-08-09 수정: 조사 디테일 강화 — 결과 개수 기본값을 5→8로 늘리고,
        // 각 결과의 snippet(본문 요약)도 있으면 함께 프롬프트에 포함시켜, Gemini가
        // 제목/URL만 보고 추측하는 것이 아니라 실제 웹 콘텐츠 요약까지 참고해
        // 조사하도록 그라운딩 블록을 더 풍부하게 구성한다.
        // add_query_arg()는 값 자체를 인코딩하지 않으므로 rawurlencode()를 직접
        // 적용해 넘긴다(공백·한글 등 검색어에 특수문자가 있어도 안전하게 처리).
        $request_url = add_query_arg(
            [ 'q' => rawurlencode( $query ), 'engine' => 'all' ],
            $endpoint
        );

        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $remaining = $this->time_left();
            if ( $remaining < 4 ) break;
            // Phase A/B의 Gemini 호출용 시간을 남겨두기 위해, 남은 예산을 넘지 않도록
            // 개별 호출 타임아웃을 동적으로 제한한다(최대 20초).
            $call_timeout = (int) max( 4, min( 20, floor( $remaining ) - 2 ) );

            $response = wp_remote_get( $request_url, [
                'timeout' => $call_timeout,
                'headers' => array_filter( [
                    'X-AIBP-Secret' => $secret !== '' ? $secret : null,
                ] ),
            ] );

            if ( is_wp_error( $response ) ) continue;
            if ( wp_remote_retrieve_response_code( $response ) !== 200 ) continue;

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $data ) ) continue;

            // cloud-press 응답은 providers[].results[] 구조다. 모든 provider의
            // 결과를 하나의 목록으로 평탄화해서 기존 그라운딩 블록 형식과 동일하게 사용한다.
            $flat_results = [];
            if ( ! empty( $data['providers'] ) && is_array( $data['providers'] ) ) {
                foreach ( $data['providers'] as $provider ) {
                    if ( ! empty( $provider['results'] ) && is_array( $provider['results'] ) ) {
                        foreach ( $provider['results'] as $r ) $flat_results[] = $r;
                    }
                }
            } elseif ( ! empty( $data['results'] ) && is_array( $data['results'] ) ) {
                // /api/search 응답이나 구버전 호환을 위해 평탄화된 results도 지원.
                $flat_results = $data['results'];
            }

            if ( empty( $flat_results ) ) continue;

            $summary_text = ! empty( $data['summary'] ) ? trim( $data['summary'] ) : implode(
                ' / ',
                array_filter( array_map( function ( $r ) { return $r['title'] ?? ''; }, array_slice( $flat_results, 0, 5 ) ) )
            );

            $block = "[검색 그라운딩 — 최신 웹 정보]\n" . $summary_text . "\n";

            $lines = [];
            foreach ( array_slice( $flat_results, 0, $max_results ) as $r ) {
                if ( empty( $r['title'] ) && empty( $r['url'] ) ) continue;
                $line = '- ' . ( $r['title'] ?? '' ) . ( ! empty( $r['url'] ) ? ' (' . $r['url'] . ')' : '' );
                // API가 snippet/content/description 등 어떤 키로 본문 요약을 주든
                // 최대한 흡수해서 조사 디테일을 높인다.
                $snippet = '';
                foreach ( [ 'snippet', 'content', 'description', 'summary' ] as $snippet_key ) {
                    if ( ! empty( $r[ $snippet_key ] ) && is_string( $r[ $snippet_key ] ) ) {
                        $snippet = $r[ $snippet_key ];
                        break;
                    }
                }
                if ( $snippet !== '' ) {
                    if ( function_exists( 'mb_substr' ) && mb_strlen( $snippet ) > 300 ) {
                        $snippet = mb_substr( $snippet, 0, 300 ) . '…';
                    } elseif ( strlen( $snippet ) > 300 ) {
                        $snippet = substr( $snippet, 0, 300 ) . '…';
                    }
                    $line .= "\n  " . trim( $snippet );
                }
                $lines[] = $line;
            }
            if ( $lines ) $block .= "\n출처 및 상세 내용:\n" . implode( "\n", $lines ) . "\n";

            return $block . "\n";
        }
        return '';
    }

    /* ── AI 썸네일 전용: 검색 그라운딩 Worker에게 "주제 조사"까지 통째로 맡긴다 ──
       ⚠️ 2026-08(3차) 신설: 기존 fetch_search_grounding()은 검색 결과 텍스트만
       가져와서 그 뒤에 Gemini가 다시 조사(actual_meaning/visual_context 등 JSON화)
       하는 구조였다. Worker가 검색과 조사를 모두 마친 뒤 곧바로 그 JSON
       (research 필드)을 반환하도록 개편되었으므로, 이 함수는 Worker 응답의
       research 필드를 그대로 받아 쓴다. Gemini 조사 호출은 더 이상 존재하지
       않는다.
       ⚠️ 2026-08(4차) 개편: 기존 Groq 기반 워커(aibp-search-grounding-groq,
       worker-groq.js) 대신 cloud-press(GitHub: choichoi3227-crypto/cloud-press,
       검색 그라운딩 전용 endpoint를 호출한다.
       요청/응답 스펙(POST, { query, max_results, country, research:true } →
       { research: {...} })은 기존과 동일하게 유지되도록 cloud-press 쪽을
       맞춰두었으므로, 이 함수의 파싱 로직은 URL 조립 부분만 바뀌고 그대로다.
       cloud-press의 /api/search는 기본적으로 규칙 기반(색상/카테고리 사전)
       으로 동작하며, Cloudflare AI 바인딩 없이도 항상 완전한 응답을 준다
       (AI 바인딩은 저장소 쪽의 선택 사항이며 이 플러그인과는 무관하다).
       ⚠️ "이미지는 반드시 Worker의 실제 조사 내용을 근거로 생성되어야 한다"는
       요구사항에 따라, 여기서 실패하면 로컬 키워드 매칭으로 조용히 대체하지
       않는다 — 재시도까지 모두 실패하면 WP_Error를 반환해 호출부가 사용자에게
       명확한 오류를 보여주도록 한다. */


    private function call_gemini_api( $body, $timeout = 130, $model = 'gemini-3.5-flash' ) {
        $keys = $this->get_api_keys();
        if ( empty( $keys ) ) return new WP_Error( 'no_api_key', 'Gemini API 키가 설정되지 않았습니다. 설정 페이지에서 API 키를 최소 1개 입력해주세요.' );
        $excluded = [];
        // 키당 최대 3번씩, 최소 2회 ~ 최대 12회까지 재시도 (실제 반복 횟수는 아래 데드라인이 최종 결정한다).
        $max_try   = max( 2, min( count( $keys ) * 3, 12 ) );
        $last_code = null;
        $last_err  = '';
        $quota_ids_str = '';

        // ⚠️ 전체 작업(요청 하나)의 절대 데드라인(request_deadline)을 절대 넘기지 않는다.
        // 개별 호출의 $timeout도 남은 시간보다 길면 남은 시간에 맞춰 자른다.
        $started_at = microtime( true );

        for ( $attempt = 0; $attempt < $max_try; $attempt++ ) {
            $remaining = $this->time_left();
            // 최소한의 왕복 시간(2초)도 없으면 더 시도해봐야 의미가 없으므로 즉시 중단
            if ( $remaining < 2 ) break;

            $api_key = $this->get_next_api_key( $keys, $excluded );
            if ( ! $api_key ) {
                $wait = min( 3, max( 0, $remaining - 1 ) );
                if ( $wait <= 0 ) break;
                sleep( (int) ceil( $wait ) );
                $excluded = [];
                $api_key  = $keys[0];
            }

            // 이 호출에 실제로 쓸 수 있는 timeout: 함수 인자로 받은 값과 "남은 예산" 중 더 작은 쪽.
            $remaining      = $this->time_left();
            if ( $remaining < 2 ) break;
            $call_timeout   = max( 2, (int) min( $timeout, floor( $remaining ) - 1 ) );

            $api_url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
            $response = wp_remote_post( $api_url, [
                'timeout' => $call_timeout,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
            ] );

            if ( is_wp_error( $response ) ) {
                $last_code = null;
                $last_err  = $response->get_error_message();
                $remaining = $this->time_left();
                if ( $attempt < $max_try - 1 && $remaining > 3 ) { sleep( 1 ); continue; }
                return new WP_Error( 'api_error', 'API 요청 실패(네트워크 오류): ' . $last_err );
            }

            $code = wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code === 200 ) return $data;

            $last_code = $code;
            $last_err  = isset( $data['error']['message'] ) ? $data['error']['message'] : ( 'HTTP ' . $code );

            // 401/403 등 인증·권한 오류는 재시도해도 결과가 같으므로 즉시 종료
            if ( in_array( $code, [ 400, 401, 403 ], true ) ) {
                return new WP_Error( 'api_error', 'API 오류 (HTTP ' . $code . '): ' . $last_err . ' — API 키 또는 요청 형식을 확인해주세요.' );
            }

            // 429(Rate Limit) 처리: "일일 할당량이 완전히 소진된 경우"와
            // "분당/초당 요청 한도에 순간적으로 걸려 잠깐 후엔 풀리는 경우"는 근본적으로 다르다.
            // ⚠️ 주의: Google 응답 메시지에는 RPM(분당) 초과든 RPD(일일) 초과든 거의 항상 "quota"라는
            // 단어가 공통으로 들어간다. 따라서 메시지에 "quota"가 있는지만으로는 절대 구분할 수 없고,
            // 반드시 QuotaFailure.violations[].quotaId 안에 "PerDay"가 있는지로 판별해야 한다.
            // (참고: https://ai.google.dev/gemini-api/docs/rate-limits)
            $violations = [];
            if ( isset( $data['error']['details'] ) && is_array( $data['error']['details'] ) ) {
                foreach ( $data['error']['details'] as $detail ) {
                    if ( isset( $detail['violations'] ) && is_array( $detail['violations'] ) ) {
                        $violations = array_merge( $violations, $detail['violations'] );
                    }
                }
            }
            $quota_ids     = array_map( function( $v ) { return isset( $v['quotaId'] ) ? $v['quotaId'] : ''; }, $violations );
            $quota_ids_str = implode( ' ', $quota_ids );

            // 서버가 알려주는 재시도 대기시간(RetryInfo.retryDelay, 예: "34s")이 있으면 그대로 존중한다.
            $retry_delay_seconds = null;
            if ( isset( $data['error']['details'] ) && is_array( $data['error']['details'] ) ) {
                foreach ( $data['error']['details'] as $detail ) {
                    if ( isset( $detail['retryDelay'] ) && preg_match( '/([\d.]+)s/', $detail['retryDelay'], $m ) ) {
                        $retry_delay_seconds = (float) $m[1];
                    }
                }
            }

            // "PerDay"(일일 한도) violation이 하나라도 있으면 그 키는 오늘 완전히 소진된 것 —
            // 재시도해도 실패가 확정이므로 그 키는 제외하고 다른 키로 넘어가되, 대기는 하지 않는다.
            // 반대로 PerDay가 전혀 없다면(PerMinute/PerSecond 등 단기 한도만 있거나, quotaId를 못 받은 경우)
            // 일시적 rate limit로 간주하고 정상적으로 백오프 후 재시도한다.
            $is_daily_quota_exhausted = ( $code === 429 ) && stripos( $quota_ids_str, 'PerDay' ) !== false;

            if ( $is_daily_quota_exhausted ) {
                $excluded[] = $api_key;
                // 아직 시도 안 해본 다른 키(다른 프로젝트일 수도 있음)가 있으면 그것만 즉시 한 번 더 시도.
                // 대기(sleep) 없이 넘어간다 — 일일 한도는 몇 초 기다린다고 풀리지 않는다.
                $remaining = $this->time_left();
                $has_other_key = (bool) $this->get_next_api_key( $keys, $excluded );
                if ( $has_other_key && $remaining > 2 ) { continue; }
                return new WP_Error(
                    'quota_exceeded',
                    'Gemini API 일일 할당량이 모두 소진되었습니다 (HTTP 429 — daily quota exceeded). ' .
                    '이는 몇 초 기다린다고 해결되지 않으며, 다음 중 하나가 필요합니다: ' .
                    '① 다른 Google Cloud 프로젝트에서 발급한 API 키 등록(같은 프로젝트 키를 여러 개 넣어도 쿼터는 공유되어 효과 없음), ' .
                    '② 해당 프로젝트에 결제(billing) 등록으로 유료 등급 전환, ' .
                    '③ 무료 티어 일일 한도 초기화(태평양시간 자정) 대기.'
                );
            }

            // 429(분당/초당 요청 한도 등 일시적 Rate Limit) 또는 503(서버 과부하)
            // → 해당 키 제외 후 다음 키로, 남은 예산 안에서만 백오프 후 재시도.
            // 서버가 retryDelay를 알려줬다면 그 시간을(예산 내에서) 우선 사용한다.
            if ( in_array( $code, [ 429, 503 ], true ) ) {
                $excluded[] = $api_key;
                $remaining  = $this->time_left();
                if ( $remaining < 3 ) break;
                if ( null !== $retry_delay_seconds ) {
                    $wait = (int) max( 1, min( ceil( $retry_delay_seconds ), 15, floor( $remaining - 1 ) ) );
                } else {
                    $wait = (int) max( 1, min( pow( 2, $attempt ), 5, floor( $remaining - 1 ) ) );
                }
                if ( $wait <= 0 ) break;
                sleep( $wait );
                continue;
            }

            // 그 외(5xx 등 일시 오류로 볼 수 있는 코드)는 남은 예산이 있을 때만 짧게 대기 후 재시도
            $remaining = $this->time_left();
            if ( $attempt < $max_try - 1 && $remaining > 3 ) { sleep( 1 ); continue; }
            return new WP_Error( 'api_error', 'API 오류 (HTTP ' . $code . '): ' . $last_err );
        }

        // 재시도를 모두 소진했거나 데드라인을 초과한 경우 — 마지막으로 확인된 실제 원인을 그대로 노출
        $actual_tries = min( $attempt + 1, $max_try );
        $reason = $last_code
            ? ( 'HTTP ' . $last_code . ' — ' . $last_err )
            : ( $last_err ?: '알 수 없는 오류' );
        $quota_debug = ! empty( $quota_ids_str ) ? ( ' [quotaId: ' . $quota_ids_str . ']' ) : ' [quotaId 정보 없음 — Google이 상세 violations를 응답에 포함하지 않음]';
        return new WP_Error(
            'api_error',
            'API 요청이 ' . $actual_tries . '회 재시도 후에도 실패했습니다 (마지막 원인: ' . $reason . ')' . $quota_debug . '. ' .
            ( in_array( $last_code, [ 429, 503 ], true )
                ? 'Gemini 무료 티어의 분당/일일 요청 한도에 도달했을 가능성이 높습니다. 1~2분 후 다시 시도하거나, 설정 페이지에서 API 키를 추가로 등록(콤마로 구분)하면 자동 분산되어 실패 확률이 줄어듭니다.'
                : '잠시 후 다시 시도해주세요.' )
        );
    }

    /* ── AJAX: 콘텐츠 생성 ── */
    public function ajax_generate_content() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $this->set_request_deadline(); // 전체 작업 55초 하드 캡 시작 (CloudFront 등 504 방지)
        $topic              = isset( $_POST['topic'] )              ? sanitize_text_field( $_POST['topic'] )              : '';
        $type               = isset( $_POST['type'] )               ? sanitize_text_field( $_POST['type'] )               : 'informational';
        $post_id            = isset( $_POST['post_id'] )            ? absint( $_POST['post_id'] )                          : 0;
        if ( empty( $topic ) ) wp_send_json_error( [ 'message' => '주제를 입력해주세요.' ] );

        try {
            $result = $this->generate_blog_content( $topic, $type );
            if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );

            $meta_info = isset( $result['meta_info'] ) ? $result['meta_info'] : [];
            // 제목 자동 삽입 제거 — 포스트 제목은 사용자가 직접 작성
            unset( $meta_info['title'] );

            if ( $post_id > 0 ) {
                // SEO 메타 저장 (제목 제외)
                if ( ! empty( $meta_info['meta_desc'] ) )     update_post_meta( $post_id, '_ai_meta_desc',     sanitize_text_field( $meta_info['meta_desc'] ) );
                if ( ! empty( $meta_info['slug'] ) )          update_post_meta( $post_id, '_ai_slug',          sanitize_text_field( $meta_info['slug'] ) );
                if ( ! empty( $meta_info['focus_keyword'] ) ) update_post_meta( $post_id, '_ai_focus_keyword', sanitize_text_field( $meta_info['focus_keyword'] ) );

                // 슬러그 업데이트 (한글 유지)
                if ( ! empty( $meta_info['slug'] ) ) {
                    $korean_slug = $meta_info['slug'];
                    global $wpdb;
                    $wpdb->update( $wpdb->posts, [ 'post_name' => $korean_slug ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );
                }

                // Rank Math SEO 자동 연동 (제목 제외)
                if ( ! empty( $meta_info['meta_desc'] ) )     update_post_meta( $post_id, 'rank_math_description',     sanitize_text_field( $meta_info['meta_desc'] ) );
                if ( ! empty( $meta_info['focus_keyword'] ) ) update_post_meta( $post_id, 'rank_math_focus_keyword',   sanitize_text_field( $meta_info['focus_keyword'] ) );
            }

            $raw  = $result['html'];
            $raw  = preg_replace( '/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $raw );
            $raw  = preg_replace( '/\*(.+?)\*/us', '$1', $raw );
            $raw  = str_replace( '*', '', $raw );
            // ── H4 태그 완전 제거: h4 → h3 로 상향 변환 (콘텐츠 손실 없이 제거) ──
            $raw  = preg_replace( '/<h4([^>]*)>/i', '<h3$1>', $raw );
            $raw  = preg_replace( '/<\/h4>/i', '</h3>', $raw );
            $html = $this->strip_seo_from_content( $raw );
            $html = $this->ensure_description_first( $html, $meta_info['meta_desc'] ?? '', $topic );

            wp_send_json_success( [ 'html' => $html, 'meta_info' => $meta_info ] );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => '오류: ' . $e->getMessage() ] );
        }
    }

    /* ── AJAX: 콘텐츠 확장 (선택 문장 → 3~4문장 인라인 확장) ── */
    public function ajax_expand_content() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $this->set_request_deadline(); // 전체 작업 55초 하드 캡 시작 (CloudFront 등 504 방지)
        $selected     = isset( $_POST['selected_text'] ) ? sanitize_textarea_field( $_POST['selected_text'] ) : '';
        $full_content = isset( $_POST['full_content'] )  ? sanitize_textarea_field( $_POST['full_content'] )  : '';
        $post_title   = isset( $_POST['post_title'] )    ? sanitize_text_field( $_POST['post_title'] )        : '';
        if ( empty( $selected ) ) wp_send_json_error( [ 'message' => '확장할 텍스트를 선택해주세요.' ] );

        $context_block = '';
        if ( ! empty( $post_title ) )   $context_block .= "글 제목: {$post_title}\n";
        if ( ! empty( $full_content ) ) $context_block .= "전체 글 내용(일부):\n" . mb_substr( $full_content, 0, 1500, 'UTF-8' ) . "\n";

        $search_query    = ! empty( $post_title ) ? $post_title : mb_substr( $selected, 0, 60, 'UTF-8' );
        $grounding_block = $this->fetch_search_grounding( $search_query, 5 );
        if ( ! empty( $grounding_block ) ) $context_block = $grounding_block . $context_block;

        $prompt = "당신은 한국어 블로그 전문 작가입니다.
아래 [원본 문장]을 3~4개 문장으로 확장하여 반환하세요.

[전체 글 컨텍스트]
{$context_block}

[원본 문장 — 이 문장을 3~4개 문장으로 확장]
{$selected}

【핵심 지시사항】
1. 원본 문장의 핵심 내용과 의미를 반드시 유지하세요.
2. 원본 문장을 더 구체적이고 상세하게 풀어서 3~4개 문장으로 확장하세요.
3. 원본 문장의 내용을 첫 문장에 자연스럽게 포함시키세요.
4. 추가 문장들은 구체적인 수치·예시·이유로 원본 내용을 뒷받침하세요.
5. 전체 글의 흐름과 주제에 맞게 자연스럽게 이어지도록 작성하세요.
6. 각 문장은 반드시 20~70자 이내로 작성하세요 (너무 짧아도 너무 길어도 안 됨).

⚠️ 절대 금지
- 원본 문장과 무관한 새로운 주제 도입 금지
- 새로운 H2/H3 섹션 생성 금지 (인라인 확장만)
- 마크다운, 별표(*), 한자 금지
- '결론적으로', '이상으로', '살펴보았습니다', '알아보았습니다', '정리해드리겠습니다' 등 마무리·안내 표현 금지
- '본문에서는', '이어서는', '다음 섹션에서는' 등 구조 안내 표현 금지
- 15자 미만의 너무 짧은 문장 금지
- 80자 초과의 너무 긴 문장 금지
- 500자 초과 금지 (간결하게 3~4문장만)

✅ 출력 형식 (반드시 준수)
- 확장된 3~4개 문장만 출력
- HTML 태그 완전 금지 (p태그·br태그·h태그 모두 금지)
- 마크다운 완전 금지 (별표·샵·백틱 모두 금지)
- 줄바꿈 없이 이어지는 하나의 단락
- 한국어만 사용 / 영어·한자 금지
- 앞에 번호·기호·따옴표 붙이지 말 것

오직 확장된 3~4개 문장만 출력하세요:";

        $body = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
            'generationConfig' => [ 'temperature' => 0.70, 'maxOutputTokens' => 600 ],
        ];
        $data = $this->call_gemini_api( $body, 60, 'gemini-3.5-flash' );
        if ( is_wp_error( $data ) ) wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        $text = $this->extract_text( $data );
        if ( is_wp_error( $text ) ) wp_send_json_error( [ 'message' => $text->get_error_message() ] );

        // 마크다운·코드블록·HTML 태그 정리
        $text = preg_replace( '/```[\s\S]*?```/i', '', $text );
        $text = preg_replace( '/\*\*(.+?)\*\*/us', '$1', $text );
        $text = str_replace( '*', '', $text );
        $text = strip_tags( $text );  // 순수 텍스트만 반환
        $text = trim( $text );

        // 최소 글자 검증
        if ( mb_strlen( $text, 'UTF-8' ) < 30 ) {
            wp_send_json_error( [ 'message' => '확장 결과가 너무 짧습니다. 다시 시도해주세요.' ] );
        }

        wp_send_json_success( [ 'expanded_text' => $text, 'message' => 'AI 콘텐츠 확장 완료' ] );
    }

        /* ── AJAX: 스키마 생성 ── */
    public function ajax_generate_schema() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $this->set_request_deadline(); // 전체 작업 55초 하드 캡 시작 (CloudFront 등 504 방지)
        $post_id     = isset( $_POST['post_id'] )     ? absint( $_POST['post_id'] )                  : 0;
        $schema_type = isset( $_POST['schema_type'] ) ? sanitize_text_field( $_POST['schema_type'] ) : '';
        $content_raw = isset( $_POST['content'] )     ? wp_strip_all_tags( $_POST['content'] )       : '';
        if ( ! $post_id || ! $schema_type ) wp_send_json_error( [ 'message' => '포스트 ID 또는 스키마 유형이 없습니다.' ] );

        $title     = get_post_meta( $post_id, '_ai_seo_title',     true ) ?: get_the_title( $post_id );
        $meta_desc = get_post_meta( $post_id, '_ai_meta_desc',     true ) ?: '';
        $focus_kw  = get_post_meta( $post_id, '_ai_focus_keyword', true ) ?: '';
        $post_url  = get_permalink( $post_id ) ?: get_site_url();
        $site_name = get_bloginfo( 'name' ) ?: '블로그';
        if ( empty( $content_raw ) ) {
            $post_obj    = get_post( $post_id );
            $content_raw = $post_obj ? wp_strip_all_tags( $post_obj->post_content ) : '';
        }

        $prompt = $this->build_schema_prompt( $schema_type, $title, $meta_desc, $focus_kw, $content_raw, $post_url, $site_name );
        $body   = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'maxOutputTokens'  => 3000,
                'topP'             => 0.8,
                'responseMimeType' => 'application/json',
            ],
        ];
        $data   = $this->call_gemini_api( $body, 60, 'gemini-3.5-flash' );
        if ( is_wp_error( $data ) ) wp_send_json_error( [ 'message' => $data->get_error_message() ] );

        $json_text = '';
        if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
            foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
                if ( isset( $part['text'] ) ) $json_text .= $part['text'];
            }
        }

        // 마크다운 코드블록 제거 (```json ... ``` / ``` ... ```)
        $json_text = preg_replace( '/^```(?:json)?\s*/im', '', $json_text );
        $json_text = preg_replace( '/```\s*$/m', '', $json_text );
        // BOM 및 불가시 제어문자 제거
        $json_text = preg_replace( '/^\xEF\xBB\xBF/', '', $json_text );
        $json_text = trim( $json_text );

        $decoded = json_decode( $json_text, true );

        // 1차 실패: 텍스트 내 첫 번째 JSON 오브젝트 추출 시도
        if ( ! $decoded ) {
            if ( preg_match( '/(\{[\s\S]*\})/m', $json_text, $m ) ) {
                $decoded = json_decode( $m[1], true );
            }
        }

        // 2차 실패: trailing 콤마 제거 후 재시도
        if ( ! $decoded ) {
            $cleaned = preg_replace( '/,\s*([\}\]])/m', '$1', $json_text );
            $decoded = json_decode( $cleaned, true );
            if ( ! $decoded && preg_match( '/(\{[\s\S]*\})/m', $cleaned, $m ) ) {
                $decoded = json_decode( $m[1], true );
            }
        }

        if ( ! $decoded ) {
            wp_send_json_error( [ 'message' => '스키마 JSON 파싱 실패. 다시 시도해주세요. (오류: ' . json_last_error_msg() . ')' ] );
        }

        // ✅ v4.0 멀티 스키마 완전 재작성: 기존 스키마 로드 → 같은 타입만 교체 → 저장
        $existing_raw = get_post_meta( $post_id, '_ai_blog_schema_markup', true );
        $schemas      = $this->decode_schemas( $existing_raw );

        // 같은 타입은 제거 (교체), 다른 타입은 반드시 보존 → 멀티 스키마 핵심
        $schemas = array_values( array_filter( $schemas, function( $s ) use ( $schema_type ) {
            return ! ( isset( $s['type'] ) && $s['type'] === $schema_type );
        } ) );

        // 새 스키마 추가 (json은 PHP 배열로 — save_schemas가 객체로 저장)
        $schemas[] = [
            'type' => $schema_type,
            'json' => $decoded,   // PHP array → save_schemas가 처리
        ];

        // 저장 (내부에서 json 필드를 객체로 변환하여 이중인코딩 없이 저장)
        $schemas = $this->save_schemas( $post_id, $schemas );

        // 미리보기용 html (JS에서 표시만 사용)
        $schema_html_preview = '<script type="application/ld+json">' . "\n" . json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n</script>";

        wp_send_json_success( [
            'schema'   => $schema_html_preview,
            'type'     => $schema_type,
            'schemas'  => $schemas,
            'message'  => '스키마가 추가되었습니다. (총 ' . count( $schemas ) . '개)',
        ] );
    }

    /* ── AJAX: 스키마 저장 ── */
    public function ajax_save_schema_markup() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $post_id     = isset( $_POST['post_id'] )     ? absint( $_POST['post_id'] )                  : 0;
        $schema_type = isset( $_POST['schema_type'] ) ? sanitize_text_field( $_POST['schema_type'] ) : 'schema';
        $schema_json = isset( $_POST['schema_json'] ) ? wp_unslash( $_POST['schema_json'] )          : '';
        $edit_idx    = isset( $_POST['edit_idx'] )    && $_POST['edit_idx'] !== '' ? (int) $_POST['edit_idx'] : null;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) )
            wp_send_json_error( [ 'message' => '권한 없음' ] );

        // JSON 유효성 검사
        $decoded = json_decode( $schema_json, true );
        if ( ! $decoded ) wp_send_json_error( [ 'message' => '유효하지 않은 JSON입니다. 문법을 확인하세요.' ] );

        $json_pretty = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $schemas = $this->decode_schemas( get_post_meta( $post_id, '_ai_blog_schema_markup', true ) );

        if ( $edit_idx !== null && isset( $schemas[ $edit_idx ] ) ) {
            // ── 편집 모드: 특정 인덱스만 수정 ──
            $schemas[ $edit_idx ]['json'] = $decoded;   // PHP array
            $schemas[ $edit_idx ]['type'] = $schema_type;
            unset( $schemas[ $edit_idx ]['html'] );
        } else {
            // ── 신규 추가 모드: 같은 타입 제거 후 추가 ──
            $schemas = array_values( array_filter( $schemas, function( $s ) use ( $schema_type ) {
                return ! ( isset( $s['type'] ) && $s['type'] === $schema_type );
            } ) );
            $schemas[] = [ 'type' => $schema_type, 'json' => $decoded ];  // PHP array
        }

        $schemas = $this->save_schemas( $post_id, $schemas );
        wp_send_json_success( [ 'message' => '스키마가 저장되었습니다.', 'schemas' => array_values( $schemas ) ] );
    }

    /* ── AJAX: 스키마 삭제 ── */
    public function ajax_delete_schema() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $index   = isset( $_POST['index'] )   ? intval( $_POST['index'] )   : -1;
        if ( ! $post_id ) wp_send_json_error( [ 'message' => '포스트 ID 없음' ] );

        if ( $index === -1 ) {
            // 전체 삭제
            delete_post_meta( $post_id, '_ai_blog_schema_markup' );
            wp_send_json_success( [ 'message' => '모든 스키마가 삭제되었습니다.', 'all' => true ] );
        } else {
            $existing_raw = get_post_meta( $post_id, '_ai_blog_schema_markup', true );
            $schemas      = $this->decode_schemas( $existing_raw );
            if ( isset( $schemas[ $index ] ) ) {
                unset( $schemas[ $index ] );
                $schemas = array_values( $schemas );
            }
            if ( empty( $schemas ) ) {
                delete_post_meta( $post_id, '_ai_blog_schema_markup' );
            } else {
                $this->save_schemas( $post_id, $schemas );
            }
            wp_send_json_success( [ 'message' => '스키마가 삭제되었습니다.', 'schemas' => array_values( $schemas ) ] );
        }
    }

    /* ── AJAX: SEO 메타 저장 ── */
    public function ajax_save_all_seo_meta() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );
        $fields = [ 'seo_title' => '_ai_seo_title', 'meta_desc' => '_ai_meta_desc', 'slug' => '_ai_slug', 'focus_keyword' => '_ai_focus_keyword' ];
        foreach ( $fields as $k => $meta ) {
            if ( isset( $_POST[ $k ] ) ) update_post_meta( $post_id, $meta, sanitize_text_field( $_POST[ $k ] ) );
        }
        // Rank Math 연동
        if ( isset( $_POST['seo_title'] ) )      update_post_meta( $post_id, 'rank_math_title',         sanitize_text_field( $_POST['seo_title'] ) );
        if ( isset( $_POST['meta_desc'] ) )      update_post_meta( $post_id, 'rank_math_description',   sanitize_text_field( $_POST['meta_desc'] ) );
        if ( isset( $_POST['focus_keyword'] ) )  update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $_POST['focus_keyword'] ) );
        wp_send_json_success( [ 'message' => 'SEO 메타가 저장되었습니다.' ] );
    }

    /* ══════════════════════════════════════════
       AI 썸네일 — Canvas API로 이미지 생성 → 미디어 저장
       각 스타일별 40가지 이상의 고유한 배경 조합
    ══════════════════════════════════════════════════════════ */

    /* ════════════════════════════════════════════════════════
       AJAX: base64 이미지 데이터 → WordPress 미디어 라이브러리 저장
       (Canvas 생성 이미지를 미디어 라이브러리에 저장)
    ════════════════════════════════════════════════════════ */
    public function ajax_generate_thumbnail() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        $post_id   = isset( $_POST['post_id'] )    ? absint( $_POST['post_id'] )                          : 0;
        $topic     = isset( $_POST['topic'] )      ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
        $image_b64 = isset( $_POST['image_data'] ) ? wp_unslash( $_POST['image_data'] )                   : '';

        // 대체 텍스트(alt) 자동 생성용 — JS에서 함께 전달하는 스타일/조사 메타데이터
        $style_key      = isset( $_POST['style'] )          ? sanitize_text_field( wp_unslash( $_POST['style'] ) )          : '';
        $actual_meaning = isset( $_POST['actual_meaning'] )  ? sanitize_text_field( wp_unslash( $_POST['actual_meaning'] ) ) : '';
        $visual_context = isset( $_POST['visual_context'] )  ? sanitize_text_field( wp_unslash( $_POST['visual_context'] ) ): '';
        $category       = isset( $_POST['category'] )        ? sanitize_text_field( wp_unslash( $_POST['category'] ) )      : '';

        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) )
            wp_send_json_error( [ 'message' => '권한 없음' ] );
        if ( empty( $image_b64 ) )
            wp_send_json_error( [ 'message' => '이미지 데이터가 없습니다.' ] );

        // ── base64 data URI 파싱 ──
        if ( ! preg_match( '/^data:(image\/[a-z0-9.+-]+);base64,(.+)$/i', $image_b64, $m ) )
            wp_send_json_error( [ 'message' => '잘못된 이미지 형식입니다.' ] );

        $img_data = base64_decode( $m[2] );

        if ( ! $img_data )
            wp_send_json_error( [ 'message' => '이미지 디코딩 실패' ] );

        $mime_type = isset( $m[1] ) ? strtolower( $m[1] ) : 'image/jpeg';
        $ext       = 'jpg';
        $is_svg    = 'image/svg+xml' === $mime_type;

        if ( $is_svg ) {
            $ext = 'svg';
        } else {
            /* ── 요구사항: 썸네일은 기본적으로 JPG로 저장하되, SVG는 보존한다. ── */
            $mime_type = 'image/jpeg';
            $ext       = 'jpg';

            if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ) {
                $src_img = @imagecreatefromstring( $img_data );
                if ( $src_img !== false ) {
                    $w = imagesx( $src_img );
                    $h = imagesy( $src_img );
                    $flat = imagecreatetruecolor( $w, $h );
                    $white = imagecolorallocate( $flat, 255, 255, 255 );
                    imagefill( $flat, 0, 0, $white );
                    imagealphablending( $flat, true );
                    imagecopy( $flat, $src_img, 0, 0, 0, 0, $w, $h );

                    ob_start();
                    imagejpeg( $flat, null, 90 );
                    $jpeg_data = ob_get_clean();

                    imagedestroy( $src_img );
                    imagedestroy( $flat );

                    if ( ! empty( $jpeg_data ) ) {
                        $img_data = $jpeg_data;
                    }
                }
            }
        }

        add_filter( 'upload_mimes', function ( $mimes ) {
            $mimes['svg'] = 'image/svg+xml';
            return $mimes;
        } );
        add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
            if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
                return $data;
            }
            $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
            if ( 'svg' === $ext ) {
                return array( 'ext' => array( 'svg' ), 'type' => 'image/svg+xml' );
            }
            return $data;
        }, 10, 4 );

        // ── WordPress 미디어 라이브러리에 등록 ──
        $upload_dir = wp_upload_dir();
        $filename   = 'zorlinq32-ai-thumb-' . $post_id . '-' . time() . '.' . $ext;
        $filepath   = $upload_dir['path'] . '/' . $filename;
        $fileurl    = $upload_dir['url']  . '/' . $filename;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $filepath, $img_data );

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_title = sanitize_file_name( $topic . ' 썸네일' );

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $mime_type,
            'post_title'     => $attach_title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $filepath, $post_id );

        if ( is_wp_error( $attachment_id ) )
            wp_send_json_error( [ 'message' => '미디어 등록 실패: ' . $attachment_id->get_error_message() ] );

        $attach_data = wp_generate_attachment_metadata( $attachment_id, $filepath );
        wp_update_attachment_metadata( $attachment_id, $attach_data );
        set_post_thumbnail( $post_id, $attachment_id );

        /* ── 요구사항: 이미지 생성 후 자동으로 대체 텍스트(alt) 및 설명(description) 삽입 (캡션 제외) ── */
        $this->set_generated_image_alt_and_description(
            $attachment_id, $topic, $style_key, $actual_meaning, $visual_context, $category
        );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $fileurl,
            'message'       => '썸네일이 생성되어 대표 이미지로 설정되었습니다. (JPG, 대체 텍스트 자동 삽입됨)',
        ] );
    }

    /* ════════════════════════════════════════════════════════
       AI 생성 썸네일 — 대체 텍스트(alt) & 설명(description) 자동 삽입
       - 캡션(post_excerpt)은 의도적으로 건드리지 않는다 (요구사항: 캡션 제외).
       - alt: 스크린 리더/SEO용 간결한 한 문장. 스타일 라벨 + 조사된 실제 의미를
         조합해 "이 주제 이미지"라는 것이 명확히 드러나도록 만든다.
       - description(post_content): 미디어 상세정보에 저장되는 조금 더 긴 설명.
    ════════════════════════════════════════════════════════ */
    private function set_generated_image_alt_and_description( $attachment_id, $topic, $style_key, $actual_meaning, $visual_context, $category ) {
        $style_labels = [
            'poster'           => '포스터',
            'minimal'          => '미니멀',
            'photo_realistic'  => '사실적 사진',
            'typography'       => '타이포그래피',
            'branding'         => '브랜딩',
            'custom_template'  => '커스텀 템플릿',
        ];
        // 2026-08 추가: 커스텀 AI 스타일(custom_style_*)은 사용자가 지정한 이름을 그대로 라벨로 사용
        if ( strpos( (string) $style_key, 'custom_style_' ) === 0 ) {
            $custom_styles_map = $this->get_custom_ai_styles();
            if ( isset( $custom_styles_map[ $style_key ]['name'] ) ) {
                $style_labels[ $style_key ] = $custom_styles_map[ $style_key ]['name'];
            }
        }
        $style_label = isset( $style_labels[ $style_key ] ) ? $style_labels[ $style_key ] : '';

        $topic = trim( $topic );
        $desc_subject = ! empty( $actual_meaning ) ? $actual_meaning : $topic;

        // alt 텍스트: "{주제} — {스타일} 스타일 블로그 썸네일 이미지" 형태로 간결하게 구성
        $alt_parts = array_filter( [ $topic, $style_label !== '' ? $style_label . ' 스타일' : '' ] );
        $alt_text  = implode( ' — ', $alt_parts );
        $alt_text  = trim( $alt_text . ' 블로그 썸네일 이미지' );
        $alt_text  = sanitize_text_field( wp_strip_all_tags( $alt_text ) );
        // alt 속성 관례상 과도하게 길지 않도록 안전하게 제한
        if ( function_exists( 'mb_substr' ) && mb_strlen( $alt_text ) > 125 ) {
            $alt_text = mb_substr( $alt_text, 0, 125 );
        }
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

        // 설명(description, post_content): 주제 + 조사된 시각 맥락 + 카테고리를 조합한 한두 문장
        $desc_sentences = [];
        $desc_sentences[] = trim( $desc_subject ) . '을(를) 주제로 생성된 AI 이미지입니다.';
        if ( $style_label !== '' ) {
            $desc_sentences[] = $style_label . ' 스타일로 제작되었습니다.';
        }
        if ( ! empty( $visual_context ) && $visual_context !== $desc_subject ) {
            $desc_sentences[] = '시각적 구성: ' . $visual_context . '.';
        }
        if ( ! empty( $category ) ) {
            $desc_sentences[] = '카테고리: ' . $category . '.';
        }
        $description = sanitize_textarea_field( implode( ' ', $desc_sentences ) );

        wp_update_post( [
            'ID'           => $attachment_id,
            'post_content' => $description, // 미디어 라이브러리 "설명" 필드
            // post_excerpt(캡션)는 의도적으로 미포함 — 요구사항: 캡션 제외
        ] );
    }

    /* ════════════════════════════════════════════════════════
       AJAX: Step 1 — Worker 조사 + Gemini 프롬프트 생성
         Phase A: 주제의 실제 의미·시각화 대상 조사는 검색 그라운딩 Worker
                  (Google Flow)가
                  검색부터 조사 JSON화까지 전부 수행한다. 이 단계에서는
                  Gemini를 호출하지 않는다. Worker 조사가 실패하면 로컬
                  키워드 매칭으로 조용히 대체하지 않고 명확한 오류를 반환한다.
         Phase B: Worker 조사 결과를 바탕으로 Gemini가 최종 FLUX.1 [schnell]
                  호환 이미지 프롬프트 "문장"을 작성한다. Gemini 호출이
                  실패하면 로컬 템플릿으로 문장만 대체하되, 조사 내용(주제
                  의미·시각 요소 등)은 여전히 Worker가 조사한 값을 사용한다.
       반환: { prompt, neg_prompt(항상 빈 값), style_label, topic_research }
       (실제 이미지 생성은 ajax_retired_image_provider_generate()가 retired image provider.ai로 처리)
    ════════════════════════════════════════════════════════ */
    public function ajax_generate_image_prompt() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        /* ⚠️ 2026-08(3차) 수정: 이 액션은 이제 "Worker 검색 → Worker 조사(JSON) →
           Gemini 프롬프트 생성" 3단계를 순서대로 거치므로, 다른 AI 액션들이 쓰는
           기본 55초 예산으로는 Worker의 2단계 호출(검색+조사, 각각 최대 20초 안팎)만
           으로도 예산을 거의 다 써버려 Phase B(Gemini 프롬프트 생성)가 시간 부족으로
           실패하는 경우가 실사용에서 확인되었다. CloudFront 등 오리진 타임아웃(약 60초)
           안에서 최대한 여유를 확보하기 위해 이 액션만 명시적으로 58초 예산을 쓴다. */
        $this->set_request_deadline( 58 );

        $topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
        $style = isset( $_POST['style'] ) ? sanitize_text_field( $_POST['style'] )               : 'poster';

        if ( empty( $topic ) )
            wp_send_json_error( [ 'message' => '주제가 없습니다.' ] );

        /* ══════════════════════════════════════════════════════
           스타일별 완전 차별화 디렉티브 (Flow 동급 품질 기준)
           각 스타일은 완전히 다른 시각 언어·구도·렌더링 방식
        ══════════════════════════════════════════════════════ */
        /*
         * 스타일 디렉티브 설계 원칙:
         *  - 모든 스타일은 AI가 이미지 안에 텍스트/글자를 직접 그리지 않도록
         *    지시한다(실제 한글 제목은 이 단계가 아니라 별도 합성 단계에서
         *    다뤄짐). 2026-08-09 이전에는 "한글 텍스트 렌더링 특화"라는
         *    이유로 qwen-image에 이 지시를 맡겼으나, qwen-image는 negative
         *    지시를 사실상 무시하는 아키텍처라 오히려 원치 않는 깨진 텍스트를
         *    그려 넣는 역효과를 냈다. 현재는 이 지시를 잘 지키는 flux를 사용.
         *  - photo_realistic 외 스타일은 인물/얼굴 요소를 배제해 결과물을 단순화
         *  - 중앙에 텍스트나 핵심 요소가 들어갈 공간이 확보되어야 함
         *  - photo_realistic만 텍스트 배치 없이 사실적 장면 그대로 사용
         */
        $no_people = 'no people, no person, no human, no face, no portrait, no character, no figure, no body parts, no hands, no eyes, no silhouette of person';

        /* ⚠️ FLUX.1 [schnell] 프롬프트 규칙 (SDXL과 다름):
           - A1111 가중치 문법 "(word:1.4)"를 지원하지 않고 리터럴 글자로 취급하므로
             전부 자연어 형용사/부사로 풀어씀 (예: (masterpiece:1.4) → "masterpiece-quality").
           - FLUX는 짧은 태그 나열보다 자연스러운 한두 문장 묘사에 더 잘 반응함.
           - negative_prompt 파라미터 자체가 없으므로 'avoid'는 프롬프트 본문에
             자연어로 녹여 넣는 용도로만 사용(예: "완전히 텍스트 없이"를 문장으로 명시). */
        $style_directives = [
            /* ── 포스터: "진짜 포스터"처럼 — 매번 다른 레이아웃/구도/장치가 무작위로 조합됨
                  ⚠️ 2026-08 확장: "화려함(glamour)" 축 추가 — 사용자가 참고로 제시한
                  홀로그램 그라디언트 + 반짝이는 파티클/빛망울 + 유광 리퀴드 블롭 오브젝트가
                  떠 있는 프리미엄 이벤트 포스터 무드를 하나의 선택 가능한 장치로 편입시킨다.
                  단, 이 축이 매번 강제되면 "포스터=항상 보라색 홀로그램"이라는 새로운
                  획일화가 생기므로 device_pool 안의 여러 옵션 중 하나로만 배치한다. ── */
            'poster' => [
                'label'      => '포스터',
                'sdxl_style' => 'real-world graphic design poster layout',
                'principle'  => 'This must look like an actual printed advertising or event poster designed by a professional graphic designer for THIS specific topic — not a generic app-promo template. The composition, layout logic, and background device must change every time depending on what the topic is (a poster for a concert, a food launch, a tech product, a fitness class, a movie, etc. all look completely different from each other).',
                'layout_pool'=> [
                    'a bold asymmetric composition with the hero visual pushed to one side and a large negative-space block reserved on the other side for the title',
                    'a dynamic diagonal split composition dividing the poster into two contrasting color zones, with the main visual breaking across the diagonal line',
                    'a centered hero-object composition with strong radial framing elements (light rays, concentric shapes, or motion lines) drawing the eye to the middle',
                    'a layered depth composition with a blurred background scene, a mid-ground hero visual in sharp focus, and floating foreground accents at the edges',
                    'a bold color-block poster composition with 2-3 large flat color panels arranged geometrically, the hero visual placed on the largest panel',
                    'a dramatic close-up hero shot filling most of the frame with a soft vignette and a clear plain strip at the top or bottom for the title',
                    'a glamorous premium-launch composition with glossy translucent liquid-blob shapes floating at the corners and a radiant glow bursting from behind the hero subject at the center',
                    'a glamorous celebratory composition with the hero subject lifted slightly off-center inside a soft radiant halo of light, tiny drifting sparkle particles scattered throughout the frame, and glossy rounded liquid-blob accents anchoring the corners — evoking a premium launch-event or hopeful celebration poster',
                ],
                'device_pool'=> [
                    'a smooth diagonal gradient background (two complementary colors blending)',
                    'a deep solid-color background with dramatic directional studio lighting and soft shadow falloff',
                    'a textured background (subtle paper grain, soft bokeh, or fine noise texture) that fits the mood of the topic',
                    'a duotone color-graded background (two-tone photographic look) matching the topic\'s mood',
                    'a dynamic background with motion-blur streaks or light trails suggesting energy and movement',
                    'a softly blurred real-world environment relevant to the topic used as a backdrop',
                    'a glamorous holographic-gradient background (purple-to-pink-to-orange iridescent blend) sprinkled with tiny sparkling light particles and soft glowing bokeh dots drifting across the frame',
                    'a glossy 3D-rendered backdrop with smooth glass-like translucent blob shapes and metallic-sheen geometric fragments floating in soft depth-of-field',
                    'a rich violet-to-coral gradient sky-like backdrop dotted with countless tiny twinkling star-like sparkle points and soft out-of-focus glowing orbs, with smooth glossy amorphous blob shapes drifting near the edges for a festive, premium-launch atmosphere',
                ],
                'lighting_pool' => [
                    'lit with dramatic hard rim lighting that carves out a bright edge around the hero subject',
                    'lit with soft, even, diffused studio light and gentle, minimal shadow',
                    'lit with a single warm key light from one side casting a long dramatic shadow',
                    'lit with cool cinematic blue-teal lighting contrasted against a warm accent highlight',
                    'lit with bright golden-hour sunlight raking across the scene at a low angle',
                    'lit with punchy, high-contrast flash-photography lighting for a bold commercial look',
                    'lit with a lavish glamorous glow — soft radiant light blooms, delicate sparkle highlights, and a subtle rainbow-sheen iridescence across glossy surfaces',
                    'lit with a jubilant festive glow — warm radiant light bursts from behind the subject, a scatter of tiny bright sparkle points across the frame, and gentle iridescent color-shift highlights on every glossy surface',
                ],
                'subject'    => 'a large, clearly recognizable hero visual that directly and unmistakably represents the topic (the actual object, product, food, activity, or scene the topic is about), rendered as the dominant focal element — not small decorative icons scattered at the edges',
                'quality'    => 'masterpiece quality, sharp clean professional print-poster-grade finish, strong visual hierarchy, magazine/billboard advertising quality',
                'avoid'      => "flat single solid color with no visual interest, tiny decorative icons as the only content, generic stock-template look, chaotic overlapping clutter at the center, any text or lettering of any kind, fake or illegible characters, any real app icons, real brand logos, or real UI screenshots, {$no_people}",
            ],
            /* ── 미니멀: 단순하고 가독성 좋게 — 여백 극대화 + 주제를 압축한 단 하나의 상징 요소 ── */
            'minimal' => [
                'label'      => '미니멀',
                'sdxl_style' => 'minimalist single-subject background',
                'principle'  => 'This must feel simple, calm, and instantly readable — maximum negative space, minimum visual noise — while still clearly hinting at the topic through exactly ONE small, extremely simplified symbolic shape or silhouette placed off-center, small, and understated. The rest of the frame stays empty so a title reads perfectly on top.',
                'layout_pool'=> [
                    'the single symbolic shape placed small in one lower corner, everything else pure empty flat color',
                    'the single symbolic shape placed small along one edge, rendered as a simple flat silhouette in a slightly darker or lighter tone than the background',
                    'the single symbolic shape reduced to a thin minimal line-art outline, placed small in a corner, the rest of the frame completely empty',
                    'an absolutely flat, textureless single-color field, pure Bauhaus/Muji-style emptiness',
                ],
                'device_pool'=> [
                    'a single flat solid background color with zero gradient or texture',
                    'a single flat background color with an extremely subtle, barely-visible fine grain texture',
                    'a single flat background color divided by one thin straight line into two very close shades of the same color',
                ],
                'lighting_pool' => [
                    'a warm, muted pastel tone family',
                    'a cool, muted earthy tone family',
                    'a deep, saturated single accent tone (jewel-tone, not pastel) used as the sole color',
                    'a neutral warm beige/off-white tone family for a paper-like calm feel',
                ],
                'subject'    => 'at most one small, radically simplified flat-silhouette shape placed off to one side that symbolically represents the topic, kept understated and small — or, for abstract topics, a purely flat empty color field',
                'quality'    => 'masterpiece quality, perfectly flat and clean, smooth Muji/Kinfolk/Bauhaus editorial minimalism',
                'avoid'      => "gradient, busy pattern, multiple competing colors, bright neon, cluttered composition, realistic detailed rendering of the subject, large centered subject, glossy holographic sheen, sparkle or glitter particles, glamorous glowing light blooms, liquid-blob or 3D-rendered shapes, any real app icons, real brand logos, or real UI screenshots, {$no_people}",
            ],
            /* ── 사실적 사진: 제미나이 프롬프트 기반 실사 이미지 ── */
            'photo_realistic' => [
                'label'      => '사실적 사진',
                'sdxl_style' => 'hyperrealistic photography',
                'principle'  => 'This must be indistinguishable from a real photograph taken by a professional photographer — the exact camera setup, lighting mood, and framing should be chosen to match what would actually be used to shoot THIS specific topic (e.g. food photography lighting for food, architectural photography for buildings, street photography grain for urban topics, studio product photography for gadgets).',
                'layout_pool'=> [
                    'shot on a full-frame DSLR with a 50mm f/1.4 lens, shallow depth of field, soft creamy bokeh behind the subject',
                    'shot with a wide-angle 24mm lens capturing full environmental context around the subject, deep focus',
                    'an overhead flat-lay composition shot from directly above with soft diffused lighting',
                    'a dramatic low-angle shot looking slightly upward at the subject, creating a sense of scale',
                    'a tight macro close-up shot revealing fine texture and detail of the subject',
                ],
                'device_pool'=> [
                    'golden-hour natural sunlight with warm long shadows',
                    'soft overcast diffused daylight with even, gentle shadows',
                    'moody artificial studio lighting with controlled highlights and deep shadows',
                    'bright clean commercial studio lighting on a seamless backdrop',
                    'ambient interior lighting with a warm, lived-in atmosphere',
                ],
                'lighting_pool' => [
                    'rendered with rich, true-to-life color grading and natural contrast',
                    'rendered with a slightly filmic, subtly warm color grade reminiscent of premium editorial photography',
                    'rendered with crisp, high-clarity commercial color grading and punchy but natural saturation',
                    'rendered with soft, slightly desaturated tones for a calm, documentary-style realism',
                ],
                'subject'    => 'the exact real-world object, place, food, activity, or scene the topic refers to, photographed authentically and accurately, with the correct real-world materials, textures, and proportions',
                'quality'    => 'raw photo quality, photorealistic, hyperrealistic detail, sharp true-to-life textures, editorial/commercial magazine quality',
                'avoid'      => 'illustration style, cartoon style, anime style, painting texture, CGI look, artificial plastic look, oversaturated colors, holographic or iridescent graphic-design effects, sparkle/glitter particle overlays, fantasy glow effects that no real camera could capture, any real, identifiable brand logos or trademarked app icons rendered on objects or screens in the scene, any text, letters, or illegible characters appearing on signs, screens, papers, or objects in the scene',
            ],
            /* ── 타이포그래피: 배경은 글자의 무대 — 글자의 곡률/유동성/크기 대비를 극대화하도록 배경도 그에 맞춰 변주 ── */
            'typography' => [
                'label'      => '타이포그래피',
                'sdxl_style' => 'typography-stage background',
                'principle'  => 'The background exists purely as a stage for expressive, flowing, dynamically-scaled lettering that will be added afterward — it must give strong visual contrast and depth so bold, fluid, size-varying, sometimes curved or arched text reads with maximum drama on top of it. The background itself must have almost no competing detail, but its color/mood must vary to match the topic\'s emotional tone.',
                'layout_pool'=> [
                    'a solid deep near-black background with extremely subtle radial darkening at the edges (vignette) and a clean glow-free center',
                    'a solid deep saturated color background (not black) chosen to match the topic\'s emotional tone, completely flat and clean',
                    'a solid dark background with a very faint, soft directional light gradient from one corner, otherwise clean and empty',
                ],
                'device_pool'=> [
                    'pure matte solid color with zero texture',
                    'a solid color with an extremely subtle soft-focus grain texture for a premium editorial feel',
                    'a solid color with a barely-visible soft vignette darkening only at the very edges',
                ],
                'lighting_pool' => [
                    'a bold, highly saturated single hue',
                    'a deep near-monochrome tone with one whisper-subtle secondary undertone',
                    'a rich jewel-tone (deep emerald, burgundy, sapphire, or amber) chosen to match the topic mood',
                    'a soft muted tone with a faint warm glow concentrated in one corner only',
                ],
                'subject'    => 'purely a clean solid stage of color and light, since dynamic flowing typography will be composited on top afterward',
                'quality'    => 'masterpiece quality, a pristine clean solid background, premium editorial/album-cover-grade finish',
                'avoid'      => "busy patterns, gradients with multiple bright zones, decorative shapes or icons competing for attention, sparkle or glitter particles, liquid-blob or 3D-rendered shapes, glossy holographic sheen, any pre-existing text or lettering, any real app icons or brand logos, {$no_people}",
            ],
            /* ── 브랜딩: CTA(행동 유도) 중심 — 시선이 CTA 영역으로 흐르는 구도가 매번 달라짐
                  ⚠️ 2026-08 확장: 포스터와 마찬가지로 "화려함(glamour)" 장치를 옵션 중 하나로
                  추가하되, CTA 유도 원칙(principle)은 절대 훼손하지 않는다 — 화려한 장식은
                  항상 CTA 존 바깥(가장자리)에만 배치되고 CTA 영역 자체는 계속 깨끗하게 비워둔다. ── */
            'branding' => [
                'label'      => '브랜딩',
                'sdxl_style' => 'premium CTA-driven brand campaign visual',
                'principle'  => 'This must function like a real premium brand campaign visual whose entire composition is engineered to guide the eye toward a call-to-action zone (a clear open area, usually lower-third or one side, reserved for a button/label such as "지금 시작하기"). The visual hierarchy — hero product/subject, lighting direction, and negative space — must all point toward that CTA zone, and the exact composition changes based on the topic. Any decorative glamour elements (glow, sparkle, gradient) must stay at the edges and never cross into the reserved CTA zone.',
                'layout_pool'=> [
                    'the hero subject placed in the upper two-thirds of the frame with a clean, distinctly separated flat-color band across the bottom reserved for a CTA button',
                    'the hero subject placed to one side with a strong leading line (light streak, edge of a shape, or gradient direction) pointing toward an empty CTA zone on the other side',
                    'a centered hero subject with a soft radial spotlight, and a clean pill-shaped empty zone near the bottom center reserved for a CTA button',
                    'a split-frame composition: top half shows the hero subject/scene, bottom half is a flat premium-color panel reserved entirely for CTA text and button',
                    'a glamorous premium-launch composition with a holographic iridescent gradient sweeping across the upper frame, tiny sparkling light particles drifting near the hero subject, and a clean matte panel at the bottom reserved for the CTA button',
                    'a glamorous festive-launch composition with the hero subject bathed in a radiant halo of soft light and a scatter of tiny sparkle particles across the upper frame, glossy rounded liquid-blob accents tucked into the corners, and a perfectly clean, sparkle-free flat-color band across the bottom reserved for the CTA button',
                ],
                'device_pool'=> [
                    'a premium palette of deep charcoal and pure white with one bold metallic-gold or brand-color accent',
                    'an all-white minimal studio background with one saturated brand-color accent block',
                    'a rich dark gradient background (navy-to-black or charcoal-to-black) with a single glowing accent-color highlight',
                    'a glamorous glossy backdrop with a soft holographic sheen (purple-pink-gold iridescence) confined to the upper edges, fading into a clean solid-color CTA band at the bottom',
                    'a glamorous violet-to-warm-coral gradient backdrop sprinkled with tiny twinkling sparkle points and softly glowing bokeh dots in the upper two-thirds, dissolving into a clean sparkle-free solid panel at the bottom reserved for the CTA button',
                ],
                'lighting_pool' => [
                    'lit with sleek, high-end product-launch spotlighting from directly above',
                    'lit with a soft glowing rim light that outlines the hero subject against the dark background',
                    'lit with clean, even softbox lighting for a crisp, trustworthy corporate feel',
                    'lit with a single dramatic side light creating bold contrast for a premium tech-launch mood',
                    'lit with a lavish red-carpet-premiere glow — warm radiant light blooms and delicate sparkle highlights around the hero subject, kept away from the CTA zone',
                    'lit with a jubilant premium-launch glow — a warm radiant halo behind the hero subject, delicate drifting sparkle highlights, and a soft rainbow-sheen iridescence on glossy surfaces, all carefully kept clear of the CTA zone',
                ],
                'subject'    => 'a polished, premium hero visual that clearly represents the product/service/topic, rendered with commercial advertising-grade lighting and finish, positioned so it visually leads toward the reserved CTA zone rather than sitting dead-center',
                'quality'    => 'masterpiece quality, commercial-grade finish, pristine premium brand feel, Apple/Nike-level advertising polish',
                'avoid'      => "amateur look, stock-photo feel, cluttered composition with no clear CTA zone, excessive neon, subject dead-center blocking the CTA area, glamour decoration spilling into or cluttering the CTA zone, any real app icons, real brand logos, or real UI screenshots, {$no_people}",
            ],
        ];

        /* ── 2026-08 추가: 사용자 정의(커스텀) AI 이미지 스타일 병합 ──
           관리자가 "AI 썸네일 템플릿" 페이지에서 추가한 커스텀 스타일은
           zorlinq32_ai_custom_styles 옵션에 저장되며, id가 'custom_style_'로
           시작한다. 각 커스텀 스타일은 반드시 5개 기본 스타일 중 하나를
           "base"로 지정하므로, 해당 base의 layout_pool/device_pool/
           lighting_pool(구도·배경·조명 풀)은 그대로 물려받고, 사용자가
           직접 입력한 principle/subject/quality/avoid/sdxl_style만 덮어쓴다.
           이렇게 하면 커스텀 스타일도 항상 유효한 배열 구조를 갖게 되어
           워드프레스 측 오류(정의되지 않은 인덱스 등)가 발생하지 않는다. */
        $custom_styles = $this->get_custom_ai_styles();
        if ( isset( $custom_styles[ $style ] ) ) {
            $custom_def = $custom_styles[ $style ];
            $base_key   = isset( $custom_def['base'] ) && isset( $style_directives[ $custom_def['base'] ] ) ? $custom_def['base'] : 'poster';
            $dir        = $style_directives[ $base_key ]; // base의 pool/구조를 그대로 상속

            foreach ( [ 'label', 'principle', 'subject', 'quality', 'avoid', 'sdxl_style' ] as $field ) {
                if ( ! empty( $custom_def[ $field ] ) ) {
                    $dir[ $field ] = $custom_def[ $field ];
                }
            }
            // avoid에 인물 배제 문구가 누락되지 않도록 안전하게 보강
            if ( strpos( $dir['avoid'], 'no people' ) === false ) {
                $dir['avoid'] .= ", {$no_people}";
            }
        } else {
            $dir = isset( $style_directives[ $style ] ) ? $style_directives[ $style ] : $style_directives['poster'];
        }

        // 매 호출마다 레이아웃/장치/조명 조합을 무작위로 선택해 "core"를 그때그때 조립합니다.
        // ⚠️ 2026-08 확장: 기존에는 layout_pool × device_pool 두 축만 조합해
        // (예: 6 × 6 = 36가지) 반복 체감이 컸다. 여기에 lighting_pool(색상/조명 축)을
        // 추가로 곱해 조합 경우의 수를 몇 배로 늘리고("n배 발전"), seed 랜덤화와
        // 합쳐지면 같은 스타일이라도 실질적으로 매번 다른 결과가 나오게 된다.
        $picked_layout   = $dir['layout_pool'][ array_rand( $dir['layout_pool'] ) ];
        $picked_device   = isset( $dir['device_pool'] )   ? $dir['device_pool'][ array_rand( $dir['device_pool'] ) ]     : '';
        $picked_lighting = isset( $dir['lighting_pool'] ) ? $dir['lighting_pool'][ array_rand( $dir['lighting_pool'] ) ] : '';

        $core_parts = [ $dir['principle'], 'Composition for this generation: ' . $picked_layout . '.' ];
        if ( '' !== $picked_device )   $core_parts[] = 'Background treatment: ' . $picked_device . '.';
        if ( '' !== $picked_lighting ) $core_parts[] = 'Color/lighting treatment for this generation: ' . $picked_lighting . '.';
        $dir['core'] = trim( implode( ' ', $core_parts ) );

        /* No Cloudflare request is made while preparing an image. Google Flow
           receives the final prompt through the user-controlled browser handoff. */
        $research_result = [
            'actual_meaning'  => $topic,
            'visual_context'  => $topic,
            'hero_shot'       => $topic,
            'key_visuals'     => [],
            'research_source' => 'local_prompt_handoff',
        ];

        /* The image is created by UseAPI Google Flow in the configured server-side account session. */

        /* ════════════════════════════════════════════════
           Phase B: 프롬프트 생성 — 위 Phase A(Worker 조사) 결과를 기반으로
           Gemini에게 최종 이미지 프롬프트 문장 작성을 요청한다. Gemini 호출이
           실패하면(키 미설정, 429 등) 로컬 템플릿 프롬프트로 대체하되, 이때도
           visual_context 등은 Worker가 조사한 값을 그대로 사용하므로 "로컬
           폴백"이 조사 내용까지 무시하지는 않는다(문장 조립 방식만 로컬로 대체).
        ════════════════════════════════════════════════ */
        $prompt_data    = $this->build_local_image_prompt( $topic, $style, $dir, $research_result['visual_context'] );
        $final_prompt   = isset( $prompt_data['prompt'] ) ? $prompt_data['prompt'] : '';
        $neg_prompt_out = isset( $prompt_data['neg_prompt'] ) ? $prompt_data['neg_prompt'] : '';
        // UseAPI Google Flow performs the final typography rendering. Do not rely
        // on the browser canvas to add a separate text layer after generation.
        $flow_title = wp_strip_all_tags( $topic );
        $final_prompt .= ' Render the exact Korean title "' . $flow_title . '" as crisp, correctly spelled, fully legible typography integrated into the composition. Match the selected visual style and reserve safe margins; do not add any other text.';

        if ( empty( $final_prompt ) ) {
            $fallback = $this->build_fallback_horde_prompt( $topic, $style, $dir, $research_result );
            wp_send_json_success( array_merge( $fallback, [
                'style_label'    => $dir['label'],
                'source'         => 'fallback',
                'topic_research' => $research_result,
            ] ) );
        }

        /* ════════════════════════════════════════════════
           Phase B: Flow 동급 SDXL 프롬프트 생성
           — 주제 정확도 + 스타일 완성도 + 글자 제거 극대화
        ════════════════════════════════════════════════ */
        $actual_meaning  = ! empty( $research_result['actual_meaning'] )  ? $research_result['actual_meaning']  : $topic;
        $visual_context  = ! empty( $research_result['visual_context'] )  ? $research_result['visual_context']  : $topic;
        $hero_shot       = ! empty( $research_result['hero_shot'] )       ? $research_result['hero_shot']       : '';
        $color_mood      = ! empty( $research_result['color_mood'] )      ? $research_result['color_mood']      : '';
        $key_visuals_arr = ! empty( $research_result['key_visuals'] )     ? (array) $research_result['key_visuals'] : [];
        $key_visuals_str = implode( ', ', $key_visuals_arr );
        $wrong_interp    = ! empty( $research_result['wrong_interpretation'] ) ? $research_result['wrong_interpretation'] : '';
        $emotional_tone  = ! empty( $research_result['emotional_tone'] )  ? $research_result['emotional_tone']  : 'dynamic';
        $text_color_hex  = $this->sanitize_hex_color_soft( $research_result['text_color_hex'] ?? '' );
        $accent_color_hex= $this->sanitize_hex_color_soft( $research_result['accent_color_hex'] ?? '' );

        $prompt_instruction = <<<PROMPT
당신은 FLUX.1 [schnell] 전문 프롬프트 엔지니어로, Flow · DALL-E 3 동급의 결과를 FLUX에서 구현합니다.
아래 조사 데이터를 바탕으로 [{$dir['label']}] 스타일의 완벽한 블로그 썸네일 프롬프트를 작성하세요.

⚠️ SVG 생성 요구사항(비사실적 스타일): 이후 이 이미지는 실제 SVG 벡터 작품으로 저장될 예정이므로, 이미지 안에 주제와 제목을 직접 나타내는 시각 요소와 텍스트가 포함되어야 하며, 텍스트는 픽셀 오버레이가 아니라 SVG 텍스트 요소로 구성할 수 있는 형태로 묘사할 것. 즉, 단순 배경이나 추상적 모양만이 아니라, 주제와 제목이 치환 없이 보이는 구조적 디자인 요소로 포함되도록 작성할 것.

━━━ 주제 조사 결과 ━━━
• 원본 키워드: {$topic}
• 실제 의미: {$actual_meaning}
• 시각화 대상: {$visual_context}
• 히어로 장면: {$hero_shot}
• 색상 분위기: {$color_mood}
• 핵심 시각 요소: {$key_visuals_str}
• ⛔ 오역 방지: {$wrong_interp}

━━━ 스타일 스펙 [{$dir['label']}] ━━━
• 핵심 디렉션(이번 생성에 배정된 구도·배경·조명 조합): {$dir['core']}
• 주제 표현법: {$dir['subject']}
• 색상 팔레트: 위 조사 결과의 색상 분위기({$color_mood})를 이 스타일의 톤에 맞게 구체적인 색상(예: HEX나 색 이름)으로 변환해서 사용하라. 스타일 원칙과 상충하지 않는 선에서 주제의 색상 분위기를 최우선으로 반영할 것.
• 스타일 태그: {$dir['sdxl_style']}
• 이 스타일의 시각적 지향(참고용 — 프롬프트에는 아래를 부정문으로 옮기지 말고, 이 방향과 반대되는 것을 자연스럽게 긍정 서술할 것): {$dir['avoid']}

━━━ ⚠️ 스타일 간 절대 혼동 금지 (매우 중요 — 사용자가 5개 스타일을 일부러 나눠둔 이유) ━━━
이 플러그인은 포스터/미니멀/사실적 사진/타이포그래피/브랜딩 5개 스타일을 사용자가 "서로 완전히 다른 결과물"을 얻기 위해 의도적으로 분리해 두었다. 같은 주제라도 스타일만 바꾸면 완전히 다른 사람이 완전히 다른 목적으로 만든 이미지처럼 보여야 하며, "비슷한 종류의 그림인데 배경색만 다르다"는 인상이 조금이라도 들면 실패다. 아래는 스타일별 전형적 어휘 대조표다 — 지금 만들 스타일은 [{$dir['label']}]이므로, 그 줄의 어휘·문법만 사용하고 다른 줄의 어휘는 절대 섞지 마라:
- 포스터: 크고 뚜렷한 히어로 오브젝트, 강한 구도 장치(대각선/색면 분할/방사형 프레이밍), 인쇄 광고 포스터 문법. 이번에 배정된 구도가 "화려함(홀로그램/반짝임/유광 블롭)" 계열이라면 그 어휘를 적극 사용하되, 배정되지 않았다면 화려함 어휘를 억지로 넣지 말 것
- 미니멀: 압도적인 빈 공간, 단 하나의 극도로 단순화된 실루엣 또는 아예 아무 형태도 없음, 절대 화려하지 않음(반짝임·홀로그램·글로시 효과 전면 금지)
- 사실적 사진: 실제 카메라 렌즈·조리개·조명 용어(예: 50mm f/1.4, golden hour), 일러스트/그래픽 요소 및 홀로그램·반짝이 효과 절대 금지
- 타이포그래피: 오직 순수한 단색/그라디언트 무대 배경뿐, 그 어떤 오브젝트나 아이콘도 존재하지 않음(글자가 나중에 합성되는 빈 무대), 화려한 장식 요소 금지
- 브랜딩: 프리미엄 상업 광고 카피처럼 CTA 영역을 향해 시선이 흐르는 구도, Apple/Nike급 상업 조명 용어. 이번에 배정된 구도가 "화려함" 계열이라면 그 화려함은 반드시 CTA 존 바깥 가장자리에만 배치
같은 주제("{$topic}")를 다른 스타일로 만들었을 때 나올 법한 결과를 지금 문장에 섞어 쓰지 마라. 예를 들어 지금 만드는 스타일이 [{$dir['label']}]이 아니라면 절대 쓰지 않을 표현이 지금 프롬프트에 들어있는지 스스로 점검하라. 특히 "화려함" 어휘(holographic, sparkle, glitter, glossy blob 등)는 포스터·브랜딩에 이번 생성에서 실제로 배정된 경우에만 등장할 수 있고, 미니멀·사실적 사진·타이포그래피에는 어떤 경우에도 등장해서는 안 된다.

━━━ FLUX.1 [schnell] 프롬프트 작성 규칙 (Cloudflare Workers AI 엔진 기준) ━━━
⚠️ 매우 중요: FLUX는 SDXL과 달리 A1111 가중치 문법 "(word:1.4)"를 아예
   지원하지 않습니다. 괄호+콜론+숫자 형식을 절대 쓰지 마세요.
⚠️ 매우 중요: FLUX.1 schnell은 negative prompt 파라미터가 없습니다.
   "넣지 말아야 할 것"은 별도 필드가 아니라 프롬프트 문장 안에
   자연어로 직접 명시해야 합니다 (예: "완전히 텍스트 없는 배경").
⚠️ 매우 중요: FLUX는 태그를 콤마로 나열하는 것보다 하나의 자연스러운
   묘사 문장(또는 이어지는 두 문장) 형태를 훨씬 더 정확히 따릅니다.
   짧은 키워드 스팸이 아니라, 장면을 실제로 설명하는 문장으로 쓰세요.
⚠️ 길이: 영어 기준 약 40-70단어. 너무 짧으면 디테일이 부족하고,
   너무 길면 schnell(distilled, 4-스텝) 모델이 핵심 지시를 놓칩니다.

① 순서 우선순위: 1)주제를 정확히 드러내는 구체적 오브젝트/장면 2)스타일 디렉션 3)색상/조명 4)품질 표현 5)표면·구성 상태에 대한 긍정 서술(⑥~⑧) — 이 순서로 자연스러운 문장에 녹여 배치
② 주제 정확도: "{$actual_meaning}" 를 오해 없이 표현하는 시각 요소를 문장 맨 앞부분에 배치
③ 구도 명시: "wide 16:9 composition" 을 자연스럽게 포함
④ 품질 표현은 스타일 고유 어휘로: 모든 스타일에 똑같이 "masterpiece quality"만 반복하지 말고, 위 스타일 스펙의 quality 문구({$dir['quality']})에서 그 스타일만의 품질 표현을 가져와 쓸 것. 스타일마다 품질을 표현하는 단어 자체가 달라야 함.
⑤ 스타일 충실도: [{$dir['label']}] 스타일이 다른 스타일과 시각적으로, 그리고 사용된 어휘 자체로도 뚜렷이 구별되어야 함
⑥ 글자·문자 배제(반드시 긍정문으로): "no text"처럼 부정형으로 쓰지 말고, 대신 표면을 "매끈하고 아무 무늬 없는(smooth, blank, unmarked)" 상태로 직접 묘사할 것. 예: "a smooth blank plain surface", "clean solid graphic outlines with no writing"이 아니라 "clean solid graphic outlines on a smooth blank surface". 아이콘·도형이 포함되는 스타일에서는 "아이콘·도형이 순수한 그래픽 윤곽선이다"라고 있는 그대로 서술하라 (없는 것을 말하지 말고 있는 상태를 말할 것).
⑥-부가 ⚠️ 화면/모니터/컴퓨터 언급 최소화 원칙 (매우 중요): 화면·모니터·컴퓨터·스마트폰 디스플레이는 "{$actual_meaning}"이 실제로 컴퓨터/스마트폰/앱/웹사이트/모니터 자체를 다루는 주제일 때만 등장시켜라. 그 외의 모든 주제(음식, 건강, 여행, 뷰티, 재테크, 교육, 반려동물, 인물 등)에서는 화면·모니터·기기 종류의 단어를 프롬프트에 아예 쓰지 마라. 화면을 부정문으로라도 언급하면(예: "화면에 글자가 없다") FLUX가 그 단어에 이끌려 오히려 화면/모니터를 그려 넣는 역효과가 실제로 매우 빈번하다 — 따라서 주제와 무관한 화면 언급은 긍정형이든 부정형이든 완전히 생략하는 것이 정답이다. 만약 주제가 실제로 화면/기기를 다룬다면, 화면 속이 텅 빈 상태라고 쓰는 대신 그 기기 자체를 하나의 조형적 오브젝트(질감, 반사, 각도)로만 묘사하고 화면 내부 내용은 언급하지 마라.
⑦ 인물·얼굴 배제 (photo_realistic 제외, 반드시 긍정문으로): "no people"처럼 부정형으로 쓰지 말 것 — 이렇게 쓰면 오히려 사람이 등장하는 역효과가 실제로 확인되었다. 대신 이미지의 시각적 초점이 처음부터 끝까지 추상적 형태·오브젝트·그래픽 요소에만 있다는 것을 긍정문으로 서술하라(예: "the composition is entirely built from abstract shapes and objects"). 사람이라는 단어 자체를 아예 프롬프트에 등장시키지 않는 것이 핵심이다.
⑧ 텍스트 삽입 공간 (photo_realistic 제외, 반드시 긍정문으로): "free of any text"처럼 부정형으로 쓰지 말고, 대신 "a large open plain area kept clean and empty at the center"처럼 그 자리의 상태를 직접 묘사할 것. 사실적 사진 스타일에서는 이 문구를 쓰지 말 것 — 실제 사진 장면에 억지로 "빈 공간"을 만들려다 오히려 알아볼 수 없는 가짜 글자가 생기는 원인이 되므로, 그 대신 자연스러운 실사 장면 구도로만 표현할 것
⑨ 반(反)템플릿 원칙: 위에서 배정된 "이번 생성에 배정된 구도·배경·조명 조합"을 반드시 그대로 따르되, 그 틀 안에서 이 주제·이 히어로 장면에서만 나올 수 있는 구체적 오브젝트·색조를 반드시 프롬프트 맨 앞부분에 명시. 다른 주제로 바꿔도 말이 되는 두루뭉술한 표현(예: "modern gradient background with icons")은 금지 — 반드시 "{$topic}"이 아니면 나올 수 없는 구체적 단어가 최소 2개 이상 포함되어야 함
⑩ 주제-이미지 매칭 최우선: 미니멀/타이포그래피처럼 배경이 단순한 스타일이라도, 조사된 히어로 장면·핵심 시각 요소를 완전히 무시하지 말고 색상 분위기·상징 요소(있는 경우)에 최대한 반영하여, 이미지를 보면 스타일과 무관하게 "이 주제구나"라고 짐작할 수 있는 단서를 남길 것
⑪ IT·앱·서비스 관련 주제 특별 주의: "다운로드", "설치", "PC버전", "업데이트" 같은 단어가 주제에 포함되면, FLUX가 학습 데이터의 강한 편향 때문에 컴퓨터 모니터 아이콘 + 채팅 말풍선 아이콘 + 다운로드 화살표 아이콘을 진부한 보라-파랑 그라디언트 배경 위에 늘어놓는 "가짜 앱스토어 광고" 클리셰로 도망치는 경우가 매우 흔하다. ⚠️ 이것은 "이렇게 나오면 안 된다"는 참고용 설명일 뿐이며, 이 문장에 등장한 단어들("모니터", "채팅 말풍선", "다운로드 화살표", "보라-파랑 그라디언트" 등)을 최종 영어 프롬프트 문장에 절대로 그대로 옮겨 적지 마라 — 부정문 형태로라도 이 단어들이 프롬프트에 등장하면 FLUX가 오히려 그 단어를 보고 똑같은 그림을 그려버린다. 이런 뻔한 아이콘 나열은 프롬프트에 언급조차 하지 말고, 대신 그 서비스/앱이 실제로 어떻게 보이는지(예: 실제 채팅 인터페이스 형태, 실제 로고 형태를 연상시키는 구체적 색상·형태, 또는 그 서비스를 사용하는 실제 장면)만 긍정문으로 표현할 것
⑫ 추상적 개념(특정 앱/브랜드/서비스의 "다운로드"·"설치"·"실행" 등 실체가 없는 행위) 주제의 법적·윤리적 처리 원칙: "{$topic}"처럼 실제 존재하는 특정 상용 소프트웨어·앱·브랜드(예: 카카오톡, 알약, 토스 등)를 가리키는 주제라도, 이미지에는 그 브랜드의 실제 로고·워드마크·트레이드드레스·특정 UI 화면을 그대로 재현하는 표현을 절대 넣지 마라 (예: "the KakaoTalk logo", "recreate the Alyac app icon", "the exact Toss app UI" 같은 문구 금지). 대신 그 서비스가 속한 카테고리(메신저/보안 소프트웨어/핀테크 등)를 일반적이고 독창적인 그래픽 은유로 표현하라 — 예를 들어 "메신저 다운로드"라는 주제는 특정 브랜드를 베끼지 않고 "대화 말풍선 형태를 연상시키는 독창적인 그래픽 오브젝트"처럼 일반화된 조형 요소로, "보안 소프트웨어"는 방패·잠금장치 같은 보편적 보안 상징으로 표현하는 식이다. 실물이 있는 스타일(사실적 사진 등)에서 실물을 그려야 할 때도 마찬가지로 특정 브랜드의 정확한 로고·화면을 재현하지 말고, 그 카테고리를 대표하는 일반적이고 독창적인 조형으로 대체하라. 이는 상표권·저작권을 침해하지 않으면서도 주제의 실제 의미와 정확히 매칭되는 이미지를 만들기 위함이다.
⑬ 스타일-주제 매칭 원칙(실물 재현 여부는 스타일이 결정): [{$dir['label']}] 스타일의 원칙("{$dir['principle']}")과 주제 표현법("{$dir['subject']}")을 다시 확인하라 — 사실적 사진 스타일처럼 "실물을 있는 그대로" 요구하는 스타일에서는 주제의 실제 오브젝트/장면을 정확한 실물 형태와 질감으로 그리고, 미니멀·타이포그래피처럼 "실물을 그리지 않는" 스타일에서는 절대 사실적인 실물 묘사로 흐르지 말고 그 스타일 고유의 추상화 방식(단순 실루엣, 순수 배경 등)을 지켜라. 스타일 원칙과 주제 정확도가 충돌하는 것처럼 느껴질 때는 항상 스타일 원칙이 우선이며, 그 스타일이 허용하는 표현 범위 안에서만 주제를 더 구체적으로 드러내라.

━━━ 필수 자가 검증(체크리스트) ━━━
프롬프트를 작성한 후, 다음을 스스로 점검하고 통과하지 못하면 프롬프트를 다시 작성하라:
- 이 프롬프트를 다른 완전히 다른 주제(예: "다이어트 식단")에 그대로 재사용해도 말이 되는가? 그렇다면 너무 추상적인 것이니 주제 고유의 구체적 단어를 더 넣어라.
- "monitor", "screen", "screen icon", "chat bubble icon", "download arrow icon", "computer", "laptop", "device interface" 같은 단어가 하나라도 등장하는가? 등장한다면, "{$actual_meaning}"이 실제로 컴퓨터/스마트폰/앱/웹서비스를 다루는 주제인지 다시 확인하라. 아니라면 그 단어들을 전부 삭제하고 주제에 맞는 실제 오브젝트로 교체한 뒤 다시 작성하라.
- 특정 브랜드/앱의 실제 로고명, 정확한 아이콘 디자인, 실제 화면 UI를 그대로 재현하라는 표현이 들어있는가? 들어있다면 전부 삭제하고 해당 카테고리를 대표하는 독창적·일반적 그래픽 은유로 교체하라.
- [{$dir['label']}] 스타일이 "실물을 있는 그대로" 요구하는 스타일인데 지금 프롬프트가 추상적 실루엣/아이콘으로만 흐르지는 않았는가? 반대로 "실물을 그리지 않는" 스타일인데 지금 프롬프트가 사실적인 실물 묘사로 흐르지는 않았는가? 스타일 원칙에 맞게 다시 조정하라.
- 지금 프롬프트에 "holographic", "sparkle", "glitter", "glossy blob", "iridescent" 같은 화려함 어휘가 들어있는가? 들어있다면 지금 스타일이 [포스터] 또는 [브랜딩]이고, 이번 생성에 배정된 구도·배경·조명 조합에 실제로 화려함 장치가 포함되어 있는지 확인하라. [미니멀]·[사실적 사진]·[타이포그래피]이거나, 화려함 장치가 배정되지 않았다면 그 어휘를 전부 삭제하라.
- ⚠️ 가장 중요: 지금 프롬프트에 "no", "not", "without", "free of", "no people", "no text" 같은 부정어·부정 표현이 단 하나라도 들어있는가? FLUX를 포함한 확산 모델은 부정어를 이해하지 못하고 오히려 그 대상을 그려 넣는다는 것이 실사용으로 확인되었다. 부정 표현이 하나라도 있다면 전부 찾아서, "없는 상태"를 직접 묘사하는 긍정문(예: "매끈하고 무늬 없는 표면", "추상적 형태만으로 구성된 화면")으로 반드시 바꿔 써라. 사람이라는 단어 자체, 텍스트라는 단어 자체를 프롬프트에 등장시키지 않는 것이 최선이다.

━━━ 출력 형식 (순수 JSON만, 마크다운 없이, 가중치 문법 절대 금지) ━━━
{
  "prompt": "40-70단어 영어 자연어 프롬프트 (한두 문장). 가중치 문법(word:1.4) 절대 사용 금지, negative_prompt 필드는 만들지 않음. 'no', 'not', 'without', 'free of' 같은 부정어를 단 하나도 쓰지 말고, 순서: 주제 시각 요소 → 스타일 → 색상/조명 → 품질 표현 → 표면/구성 상태에 대한 긍정 서술(끝부분)"
}
PROMPT;

        // Gemini API로 정교한 프롬프트 생성을 시도하고, 실패하면 이미 계산해둔
        // 로컬 프롬프트($final_prompt, 1784행)로 조용히 폴백한다 (API 키 미설정/오류 시에도
        // 이미지 생성 자체는 계속 진행될 수 있도록 하기 위함).
        $api_body = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt_instruction ] ] ] ],
            'generationConfig' => [
                'temperature'      => 0.9,
                'maxOutputTokens'  => 500,
                'topP'             => 0.9,
                'responseMimeType' => 'application/json',
            ],
        ];
        $api_data = $this->call_gemini_api( $api_body, 40, 'gemini-3.5-flash' );

        $base_prompt = '';
        if ( ! is_wp_error( $api_data ) ) {
            $api_text = $this->extract_text( $api_data );
            if ( ! is_wp_error( $api_text ) ) {
                $api_text = preg_replace( '/^```(?:json)?\s*/im', '', $api_text );
                $api_text = preg_replace( '/```\s*$/m', '', $api_text );
                $api_text = trim( $api_text );
                $api_decoded = json_decode( $api_text, true );
                if ( ! $api_decoded && preg_match( '/(\{[\s\S]*\})/m', $api_text, $m ) ) {
                    $api_decoded = json_decode( $m[1], true );
                }
                if ( is_array( $api_decoded ) && ! empty( $api_decoded['prompt'] ) ) {
                    $base_prompt = sanitize_text_field( $api_decoded['prompt'] );
                }
            }
        }

        // API가 실패했거나 유효한 프롬프트를 반환하지 못한 경우, 앞서 로컬 생성해둔
        // 프롬프트($final_prompt, 1784행 build_local_image_prompt 결과)로 대체한다.
        if ( empty( $base_prompt ) ) {
            $base_prompt = $final_prompt;
        }

        /* ⚠️ 2026-08-09 추가, 2026-08-09(2차) 강화, 2026-08(3차) 유지: 프롬프트
           사후 검증(인물 배제 규칙 강제) — 최종 안전장치.
           ⚠️ 3차 개편 이후 주제 조사(Phase A)는 검색 그라운딩 Worker가 전담하고,
           그 Worker의 조사 로직(cloud-press의 research-core.js
           buildRuleBasedResearch / enhanceWithWorkersAI) 자체에도 "visual_context/
           hero_shot/key_visuals에 인물 요소를 넣지 말라"는 지침이 이미 포함되어
           있다. 아래 검사는 그럼에도 Worker나 Gemini가 지침을 놓치는 극단적
           경우를 막기 위한 이중 방어이며, 주제 조사 자체를 로컬로 대체하는
           것이 아니라 "인물 단어가 감지된 그 순간에만" 문장 조립 방식을 로컬
           템플릿으로 바꾸는 최종 필터일 뿐이다.
           프롬프트 지시문(⑦번 규칙)에 "인물/얼굴을 넣지 마라"고 아무리 자연어로
           적어도, LLM이 이를 놓치거나(특히 주제가 "패션/스타일/포스터" 같은
           단어를 함께 담고 있을 때) hero_shot을 사람으로 상상해 실제로 인물
           프롬프트를 만들어 버리는 사례가 실사용에서 확인되었다(예: "카카오톡
           PC버전 다운로드" → 모자를 쓴 인물 화보가 나온 버그).
           ⚠️ 2차 수정 이유: 기존 코드는 "$base_prompt !== $final_prompt"일 때만,
           즉 Gemini의 2차(프롬프트 다듬기) 호출이 성공했을 때만 검사했다. 하지만
           실제 버그 원인은 그 이전 단계(Phase A 주제 조사)에서 조사 결과가
           visual_context/hero_shot 자체를 인물로 오해해 채워 넣는 경우였고, 이
           오염된 값은 Phase B가 실패하든 성공하든 로컬 폴백 프롬프트
           (build_local_image_prompt)에도 그대로 삽입된다. 즉 Phase B가 실패해
           $base_prompt가 로컬 프롬프트로 대체되는 순간 이 검사를 건너뛰어
           버그를 그대로 통과시키고 있었다. 이제는 Gemini 성공/실패와 무관하게
           최종적으로 채택된 $base_prompt를 항상 검사하고, 그마저 걸리면
           visual_context를 topic 기반 안전 폴백으로 재계산해 재구성한다. */
        if ( $style !== 'photo_realistic' ) {
            $people_leak_pattern = '/\b(person|people|woman|women|man\b|men\b|girl|boy|human|face|portrait|model wearing|model in|her (?:eyes|face|hair|hat|shoulders)|his (?:eyes|face|hair|hat|shoulders)|fashion model|hat and|wearing a hat|headshot|selfie)\b/i';
            if ( preg_match( $people_leak_pattern, $base_prompt ) ) {
                // visual_context 자체가 오염되었을 수 있으므로, Gemini 조사 결과를
                // 신뢰하지 않고 topic 키워드 매칭 기반의 안전한 로컬 개념으로
                // visual_context를 재계산한 뒤 로컬 템플릿 프롬프트를 다시 빌드한다.
                $safe_visual_context = $this->topic_to_visual_concept( $topic );
                $safe_prompt_data    = $this->build_local_image_prompt( $topic, $style, $dir, $safe_visual_context );
                $base_prompt         = $safe_prompt_data['prompt'];

                // 혹시 로컬 재빌드 결과에도 인물 관련 단어가 남아있으면(이론상
                // topic_to_visual_concept 맵에는 인물 표현이 없지만 이중 방어),
                // 완전히 중립적인 추상 도형 프롬프트로 최종 강제 대체한다.
                if ( preg_match( $people_leak_pattern, $base_prompt ) ) {
                    $base_prompt = 'Create a ' . strtolower( $dir['label'] ) . ' thumbnail background using ' . trim( $dir['sdxl_style'] )
                        . ', composed entirely of abstract geometric shapes and graphic elements with a large open plain area kept clean and empty at the center.';
                }

                if ( class_exists( 'Zorlinq32_Logger' ) ) {
                    Zorlinq32_Logger::log( 'AI 썸네일: 프롬프트에서 인물 관련 단어가 감지되어 안전한 로컬 프롬프트로 강제 대체함 (topic="' . $topic . '", style="' . $style . '")' );
                }
            }
        } else {
            /* ⚠️ 2026-08-09(2차) 추가: photo_realistic 스타일은 인물 사진이 정당한
               주제(예: 인물 인터뷰, 뷰티, 패션)도 있으므로 위의 전면 인물 배제
               필터를 적용하지 않는다. 그러나 "카카오톡 PC버전 다운로드"처럼
               topic 자체가 앱/기기/소프트웨어/서비스 다운로드를 다루는 경우에는
               사실적 사진 스타일이라도 인물이 나오는 것은 명백히 주제와 무관하다
               (이 정확한 사례가 실제 버그로 보고됨). 이런 경우에만 한정해 인물
               관련 단어를 감지하고 안전한 로컬 프롬프트로 대체한다. */
            if ( $this->topic_mentions_device_or_screen( $topic, $actual_meaning, $visual_context ) ) {
                $people_leak_pattern = '/\b(person|people|woman|women|man\b|men\b|girl|boy|human|face|portrait|model wearing|model in|her (?:eyes|face|hair|hat|shoulders)|his (?:eyes|face|hair|hat|shoulders)|fashion model|hat and|wearing a hat|headshot|selfie)\b/i';
                if ( preg_match( $people_leak_pattern, $base_prompt ) ) {
                    $safe_visual_context = $this->topic_to_visual_concept( $topic );
                    $safe_prompt_data    = $this->build_local_image_prompt( $topic, $style, $dir, $safe_visual_context );
                    $base_prompt         = $safe_prompt_data['prompt'];
                    if ( class_exists( 'Zorlinq32_Logger' ) ) {
                        Zorlinq32_Logger::log( 'AI 썸네일: photo_realistic 스타일에서 기기/앱 주제인데 인물이 감지되어 안전한 로컬 프롬프트로 강제 대체함 (topic="' . $topic . '")' );
                    }
                }
            }
        }

        // ⚠️ 2026-08 수정: 예전에는 품질 표현이 없으면 무조건 "masterpiece-quality"를
        // 앞에 붙였다. 이러면 5개 스타일 전부가 같은 단어로 시작하게 되어 "스타일이
        // 달라도 비슷하게 느껴진다"는 문제의 한 원인이 되었다. 이제는 각 스타일의
        // 고유 quality 문구($dir['quality'])에서 앞부분 표현을 가져와 스타일마다
        // 서로 다른 품질 어휘를 쓰도록 한다.
        $has_quality_word = (bool) preg_match( '/masterpiece|quality|grade|polish|finish/i', $base_prompt );
        if ( ! $has_quality_word ) {
            $style_quality_lead = trim( explode( ',', $dir['quality'] )[0] );
            $base_prompt = 'A ' . lcfirst( $style_quality_lead ) . ' image of ' . lcfirst( $base_prompt );
        }

        // ⚠️ 2026-08-09 전면 재작성: 부정문("no people", "no text") 나열 방식을 폐기했다.
        // 실사용에서 확산 모델(FLUX 포함)이 "no X"를 이해하지 못하고 오히려 X를
        // 그려 넣는 사례가 반복 확인되었다(무관한 인물, 깨진 가짜 텍스트). 이는
        // Qwen-Image만의 문제가 아니라 확산 모델 계열 전반의 공통 한계로,
        // "부정어는 그 대상 개념 자체를 활성화시킨다"는 것이 널리 보고되어 있다.
        // 이제는 "없어야 할 것"을 문장으로 나열하는 대신, "있어야 할 것"만 긍정
        // 묘사로 서술한다 — 사람 대신 다른 피사체를 명시하고, 텍스트 대신
        // "매끈한 빈 표면"을 명시하는 식으로 원하는 결과를 직접 그리게 한다.
        $subject_only_phrase = ( $style !== 'photo_realistic' )
            ? 'the sole visual focus is entirely on abstract shapes, objects, and graphic elements, '
            : '';
        // ⚠️ 2026-08 수정: "화면/모니터/디스플레이가 비어 있어야 한다"는 부정 문구를
        // 예전에는 스타일과 무관하게 매 프롬프트마다 무조건 삽입했다. FLUX는 부정문을
        // 안정적으로 이해하지 못해 "screen/monitor/display" 라는 단어 자체에 이끌려
        // 오히려 화면·모니터가 있는 장면을 그려 넣는 역효과가 매우 흔했고, 이것이
        // "모든 이미지 배경에 항상 컴퓨터가 하나 있다"는 문제의 핵심 원인이었다.
        // 이제는 주제가 실제로 기기/화면/앱/웹서비스를 다룰 때만 화면 관련 문구를 넣는다.
        $is_device_topic = $this->topic_mentions_device_or_screen( $topic, $actual_meaning, $visual_context );

        // photo_realistic은 실제 장면(간판/문서 등) 속에, 그 외 스타일은 도형·아이콘·
        // 텍스처 속에 AI가 가짜 글자·이상한 한자 같은 문자를 그려 넣는 문제가 잦으므로,
        // "텍스트가 없다"고 말하는 대신 "표면이 매끈하고 비어 있다"는 긍정 서술로 대체한다.
        if ( $style === 'photo_realistic' ) {
            $surface_phrase = 'any signs, papers, screens, or labels visible in the scene appear as smooth, blank, unmarked surfaces with soft natural reflections, ';
            if ( $is_device_topic ) {
                $surface_phrase .= 'any screen or display in the scene shows a soft, softly-lit blank glow, ';
            }
            $tail_phrase = $surface_phrase . 'wide 16:9 composition';
        } else {
            $surface_phrase = 'all icons and shapes are rendered as clean solid graphic outlines and abstract forms on smooth, blank, unmarked surfaces, ';
            if ( $is_device_topic ) {
                $surface_phrase .= 'any screen or display shows only a soft, softly-lit blank glow, ';
            }
            $tail_phrase = $surface_phrase .
                            $subject_only_phrase .
                            'with a large open plain area kept clean and empty at the center, reserved for a future title overlay, wide 16:9 composition';
        }

        $final_prompt = $this->truncate_prompt_by_words( $base_prompt, 80 ) . ', ' . $tail_phrase;

        // 스타일별 회피 요소(avoid)도 예전에는 "avoiding any X" 부정문으로 그대로
        // 프롬프트에 이어붙였으나, 같은 이유로 폐기했다. avoid 절은 이제 프롬프트에
        // 반영하지 않고, 스타일 자체의 layout_pool/device_pool/lighting_pool 등
        // 긍정 서술(위에서 이미 $base_prompt에 반영됨)로만 스타일 차이를 만든다.

        // JS 텍스트 오버레이가 참조할 시각 메타데이터 (없으면 JS 쪽에서 자체 폴백 계산)
        $research_result['emotional_tone']   = $emotional_tone;
        if ( $text_color_hex )   $research_result['text_color_hex']   = $text_color_hex;
        if ( $accent_color_hex ) $research_result['accent_color_hex'] = $accent_color_hex;

        // retired image provider 공개 엔드포인트는 별도 negative_prompt 파라미터를 받지 않으므로,
        // 여기서 만든 neg_prompt_out은 ajax_retired_image_provider_generate()에서 프롬프트
        // 문장 안에 자연어("Avoid the following entirely: ...")로 합쳐져 전달된다.
        // photo_realistic 스타일에서만 회피 문구를 별도로 채워 보낸다.
        $neg_prompt_out = '';
        if ( $style === 'photo_realistic' && ! empty( $style_avoid_phrase ) ) {
            $neg_prompt_out = $style_avoid_phrase;
        }

        // source: 조사(Phase A, 항상 검색 Worker) + 프롬프트 생성(Phase B, Gemini) 두
        // 단계의 상태를 함께 표기한다.
        // - search_worker+gemini_prompt : Worker 조사 성공 + Gemini 프롬프트 생성 성공 (정상 경로)
        // - search_worker+local_prompt  : Worker 조사는 성공했지만 Gemini 프롬프트 생성이 실패해
        //                                 로컬 템플릿 문장 조립으로 대체(조사 내용 자체는 Worker 것 그대로 사용)
        $prompt_source = is_wp_error( $api_data )
            ? 'search_worker+local_prompt'
            : 'search_worker+gemini_prompt';

        wp_send_json_success( [
            'prompt'         => $final_prompt,
            'neg_prompt'     => $neg_prompt_out,
            'style_label'    => $dir['label'],
            'source'         => $prompt_source,
            'topic_research' => $research_result,
        ] );
    }

    /**
     * 비사실적 스타일용 SVG 벡터 아트 생성.
     * 요구사항에 따라 실제 AI가 실시간으로 SVG 코드를 생성한다.
     */
    private function generate_svg_artwork( $topic, $style, $prompt, $research = [] ) {
        $style_label = '포스터';
        if ( 'minimal' === $style ) {
            $style_label = '미니멀';
        } elseif ( 'typography' === $style ) {
            $style_label = '타이포그래피';
        } elseif ( 'branding' === $style ) {
            $style_label = '브랜딩';
        } elseif ( 'photo_realistic' === $style ) {
            $style_label = '사실적 사진';
        }

        $research_text = isset( $research['actual_meaning'] ) ? sanitize_text_field( $research['actual_meaning'] ) : '';
        $visual_text   = isset( $research['visual_context'] ) ? sanitize_text_field( $research['visual_context'] ) : '';
        $tone_text     = isset( $research['emotional_tone'] ) ? sanitize_text_field( $research['emotional_tone'] ) : 'dynamic';

        $style_rules = 'poster';
        if ( 'minimal' === $style ) {
            $style_rules = 'minimal: use a sparse composition with lots of breathing room, a single dominant form, and very restrained color. The topic must still be recognizable through one strong symbolic element rather than clutter. The image must look minimal and calm, not busy or decorative. Avoid dense scenes, multiple objects, loud gradients, or decorative ornament.';
        } elseif ( 'typography' === $style ) {
            $style_rules = 'typography: make the composition text-led and typographic, with generous negative space and abstract forms shaped by the topic. The topic should appear through letterforms, layout rhythm, or symbolic marks instead of literal objects. Avoid ordinary illustration, realistic objects, and crowded scenes. The typography itself must carry the meaning.';
        } elseif ( 'branding' === $style ) {
            $style_rules = 'branding: create a premium commercial composition with a clear focal path, polished shapes, and a confident visual hierarchy. The topic must feel like a branded concept, not a generic placeholder. The overall mood should feel polished, premium, and intentional. Avoid messy collage, rough sketch energy, or amateur-looking effects.';
        } else {
            $style_rules = 'poster: use a bold hero composition with a clear focal object, strong contrast, and a dramatic layout. The topic should be the center of the visual story, not just a label. Make it feel cinematic and high-energy. Avoid subtle minimalism, plain text-only layouts, or soft decorative backgrounds.';
        }

        $svg_instruction = <<<SVG
당신은 SVG 벡터 일러스트레이션 전문가입니다. 아래 주제와 스타일을 바탕으로 단일 완전한, 독창적인 SVG 작품만 반환하세요.
- 출력은 오직 SVG 코드만, 코드블록 없이, 반드시 <svg ...>...</svg>로 시작하고 끝나야 합니다.
- 이 SVG는 실제 썸네일용이므로, 템플릿/빈 캔버스/플레이스홀더/가짜 워터마크/"AI Generated SVG" 같은 레이블을 넣지 말고, 주제와 제목이 자연스럽게 살아 있는 작품이어야 합니다.
- 주제와 스타일은 하나의 작품 안에서 동시에 작동해야 합니다. 주제를 바꾸면 구도와 시각요소도 달라져야 하고, 스타일을 바꾸면 표현 방식도 달라져야 합니다.
- 주제는 단순히 텍스트로만 나타나지 말고, 이미지 자체에서 직접 인지될 수 있어야 합니다. 예를 들어 키워드가 특정 서비스, 제품, 행동, 감정, 개념이면 그 의미를 시각적으로 드러내는 구체적인 상징·형태·장면으로 바꿔야 합니다.
- 제목 텍스트는 작품의 일부로 자연스럽게 녹아들어야 하며, 별도 박스나 단순 중앙 텍스트 레이어처럼 보이면 실패입니다. 제목은 이미지의 구조와 시각 요소의 흐름에 맞춰 배치되어야 합니다.
- 주제 상징이 반드시 보이게 하세요. 주제의 핵심 상징이 없으면 실패로 간주합니다. 최소 1개 이상의 주제 고유 시각 요소를 이미지 안에 직접 포함해야 합니다.
- 스타일마다 시각 차이를 확실히 만들어야 합니다. 포스터는 강한 포인트와 긴장감, 미니멀은 여백과 단순성, 타이포그래피는 문자 중심의 구조, 브랜딩은 프리미엄하고 정돈된 시각 계층을 각각 명확히 보여줘야 합니다.
- 같은 주제라도 스타일별로 완전히 다른 시각 언어를 보여줘야 하며, 한 스타일이 다른 스타일과 비슷하게 보이면 실패입니다. 포스터는 장면이 중심이고, 미니멀은 비어 있고 단순하며, 타이포그래피는 언어와 형태가 중심이며, 브랜딩은 상업적·프리미엄한 구조를 보여줘야 합니다.
- 각 스타일의 시그니처를 반드시 반영해야 합니다: 포스터는 드라마틱한 구도와 강한 대비, 미니멀은 단일 상징과 넓은 여백, 타이포그래피는 텍스트/문자형 요소와 구조적 리듬, 브랜딩은 명확한 시선 흐름과 세련된 브랜드 느낌.
- 텍스트는 반드시 SVG <text> 요소로 포함하고, 글꼴·색상·배치가 디자인과 자연스럽게 어우러지도록 하세요.
- 주제: {$topic}
- 실제 의미: {$research_text}
- 시각 맥락: {$visual_text}
- 스타일: {$style_label}
- 스타일 규칙: {$style_rules}
- 톤: {$tone_text}
- 참고 프롬프트: {$prompt}
- 요구: 배경, 도형, 아이콘, 텍스트, 색상, 구도, 레이아웃을 모두 SVG 안에 직접 제작하되, 템플릿이나 빈 캔버스가 아닌 실제로 주제를 구현하는 독창적 작품이어야 합니다.
- 16:9 비율로, 주제의 핵심 의미를 시각적으로 드러내는 명확한 구도를 만들고, 제목 텍스트는 작품의 일부로 자연스럽게 배치해야 합니다.
- 절대 금지: generic poster frame, empty template, stock layout, placeholder text, watermark, or any label like AI Generated SVG.
SVG;

        $body = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $svg_instruction ] ] ] ],
            'generationConfig' => [ 'temperature' => 0.35, 'maxOutputTokens' => 2200, 'topP' => 0.9 ],
        ];

        $data = $this->call_gemini_api( $body, 50, 'gemini-3.5-flash' );
        if ( is_wp_error( $data ) ) {
            return null;
        }

        $raw = $this->extract_text( $data );
        if ( is_wp_error( $raw ) ) {
            return null;
        }

        $raw = preg_replace( '/```svg\s*/i', '', $raw );
        $raw = preg_replace( '/```\s*/m', '', $raw );

        if ( preg_match( '/<svg[^>]*>.*<\/svg>/is', $raw, $m ) ) {
            $svg = $m[0];
        } else {
            $svg = $raw;
        }

        $svg = $this->sanitize_svg_markup( $svg );
        if ( empty( $svg ) ) {
            return null;
        }
        // 버그 수정: Gemini가 반환한 SVG는 종종 <text> 내용이나 속성값 안에
        // 이스케이프되지 않은 따옴표(' 또는 ")나 & 문자가 섞여 있다. 이 경우
        // 파일 자체는 문제없이 저장·업로드되지만, 브라우저는 .svg를 엄격한
        // XML로 파싱하기 때문에 "AttValue: ' expected"류의 오류로 렌더링을
        // 아예 거부해 미리보기/실제 이미지가 빈 화면(깨진 아이콘)으로 보인다.
        // → 저장 전에 실제로 XML 파싱을 시도해 유효성을 검증하고,
        //   깨져 있으면 null을 반환해 호출부가 안전한 fallback SVG로 대체하도록 한다.
        if ( ! $this->is_valid_svg_xml( $svg ) ) {
            return null;
        }
        return $svg;
    }

    /**
     * SVG 문자열이 실제로 파싱 가능한 well-formed XML인지 검증한다.
     * libxml 내부 에러 리포팅을 사용해 화면에 경고를 뿌리지 않고 조용히 검사한다.
     */
    private function is_valid_svg_xml( $svg ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            // DOMDocument를 쓸 수 없는 극히 드문 환경에서는 검증을 건너뛰고 통과시킨다
            // (기존 동작 유지 — 검증 불가가 곧 실패를 의미하진 않음).
            return true;
        }
        $prev = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $dom = new DOMDocument();
        $ok  = $dom->loadXML( $svg );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $ok && empty( $errors );
    }

    private function sanitize_svg_markup( $svg ) {
        $svg = trim( (string) $svg );
        if ( '' === $svg ) {
            return '';
        }
        if ( strpos( $svg, '<svg' ) === false ) {
            return '';
        }

        $svg = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $svg );
        $svg = preg_replace( '/on[a-z]+="[^"]*"/i', '', $svg );
        $svg = preg_replace( '/on[a-z]+=\'[^\']*\'/i', '', $svg );
        $svg = str_replace( 'javascript:', '', $svg );
        return $svg;
    }

    private function build_fallback_svg( $topic, $style ) {
        $style_label = 'poster';
        if ( 'minimal' === $style ) {
            $style_label = 'minimal';
        } elseif ( 'typography' === $style ) {
            $style_label = 'typography';
        } elseif ( 'branding' === $style ) {
            $style_label = 'branding';
        }

        $safe_topic = esc_attr( wp_strip_all_tags( $topic ) );
        $safe_topic_html = htmlspecialchars( $safe_topic, ENT_QUOTES, 'UTF-8' );
        $bg_color = '#0f172a';
        $accent_color = '#38bdf8';
        $accent2_color = '#f59e0b';
        $text_color = '#f8fafc';
        $muted_color = '#cbd5e1';
        if ( 'minimal' === $style_label ) {
            $bg_color = '#f8fafc';
            $accent_color = '#2563eb';
            $accent2_color = '#0f172a';
            $text_color = '#0f172a';
            $muted_color = '#475569';
        } elseif ( 'typography' === $style_label ) {
            $bg_color = '#111827';
            $accent_color = '#f59e0b';
            $accent2_color = '#fb923c';
            $text_color = '#fef3c7';
            $muted_color = '#fde68a';
        } elseif ( 'branding' === $style_label ) {
            $bg_color = '#111827';
            $accent_color = '#f43f5e';
            $accent2_color = '#f59e0b';
            $text_color = '#ffffff';
            $muted_color = '#f5d0fe';
        }

        $shape_markup = '<circle cx="1280" cy="220" r="240" fill="' . $accent_color . '" fill-opacity="0.18"/>';
        if ( 'minimal' === $style_label ) {
            $shape_markup = '<rect x="220" y="200" width="1160" height="500" rx="48" fill="' . $accent_color . '" fill-opacity="0.08" stroke="' . $accent_color . '" stroke-opacity="0.25" stroke-width="4"/>';
        } elseif ( 'typography' === $style_label ) {
            $shape_markup = '<rect x="220" y="180" width="1160" height="540" rx="34" fill="none" stroke="' . $accent_color . '" stroke-width="8" stroke-opacity="0.9"/><path d="M260 620 C480 500, 760 480, 980 620" stroke="' . $accent2_color . '" stroke-width="12" fill="none" stroke-linecap="round"/>';
        } elseif ( 'branding' === $style_label ) {
            $shape_markup = '<path d="M1300 180 C1440 220, 1490 360, 1350 510 C1210 660, 1040 660, 860 540" stroke="' . $accent_color . '" stroke-width="16" fill="none" stroke-linecap="round"/><rect x="250" y="200" width="980" height="500" rx="44" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.25)"/>';
        }

        // 버그 수정: 기존 코드는 '<' . $shape_markup . ' />' 형태로 조립했는데,
        // $shape_markup은 이미 <circle .../> 처럼 완결된 태그(들)이므로
        // 앞에 '<'를 또 붙이면 '<<circle .../>' 가 되어 XML 파서가
        // "StartTag: invalid element name" 오류로 렌더링을 거부한다
        // (실제 사용자 보고 오류와 정확히 일치). 뒤에 붙던 여분의 ' />'도 함께 제거.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900" viewBox="0 0 1600 900"><rect width="1600" height="900" fill="' . $bg_color . '"/><defs><linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="' . $accent_color . '" stop-opacity="0.22"/><stop offset="100%" stop-color="' . $accent2_color . '" stop-opacity="0.35"/></linearGradient></defs><rect width="1600" height="900" fill="url(#grad)"/>' . $shape_markup . '<text x="800" y="430" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="78" font-weight="700" fill="' . $text_color . '">' . $safe_topic_html . '</text><text x="800" y="515" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="30" font-weight="500" fill="' . $muted_color . '">Original ' . ucfirst( $style_label ) . ' artwork</text></svg>';
    }

    private function svg_to_data_url( $svg ) {
        $svg = trim( (string) $svg );
        if ( '' === $svg ) {
            return '';
        }
        $encoded = base64_encode( $svg );
        return 'data:image/svg+xml;base64,' . $encoded;
    }

    /* ══════════════════════════════════════════════════════
       retired image provider 경유 FLUX.1 [schnell] 호환 프롬프트 정리
       - FLUX는 A1111식 (word:1.4) 가중치 문법을 지원하지 않음
         → 그대로 리터럴 글자로 취급되어 "주제와 무관한 그림"을
         유발하는 주요 원인이었음 (SDXL과 동일한 함정)
       - FLUX.1 schnell 입력 한도는 2048자로 SDXL보다 훨씬 넉넉하지만,
         distilled 4-스텝 모델 특성상 너무 길면 핵심 지시가 흐려지므로
         여전히 프롬프트를 적정 길이로 다듬어 앞쪽에 핵심을 배치한다
    ══════════════════════════════════════════════════════ */
    private function strip_weight_syntax( $text ) {
        // (word:1.4) / (word:1.4, word2:1.2) 형태 → word / word, word2 로 변환
        $text = preg_replace( '/\(([^():]+):[\d.]+\)/', '$1', $text );
        // 혹시 남은 단순 괄호 강조 (word) 는 괄호만 제거
        $text = preg_replace( '/\(([^()]+)\)/', '$1', $text );
        // 중복 콤마/공백 정리
        $text = preg_replace( '/\s*,\s*/', ', ', $text );
        $text = preg_replace( '/,\s*,+/', ',', $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text, " ,\t\n\r\0\x0B" );
    }

    /* 콤마로 구분된 프롬프트를 앞에서부터 채워 단어 수 한도 내로 자름
       (핵심 주제 관련 구문이 앞쪽에 오도록 호출부에서 순서를 구성) */
    private function truncate_prompt_by_words( $text, $max_words = 65 ) {
        $parts = array_map( 'trim', explode( ',', $text ) );
        $out   = [];
        $count = 0;
        foreach ( $parts as $p ) {
            if ( '' === $p ) continue;
            $w = str_word_count( $p );
            if ( $count + $w > $max_words ) {
                if ( 0 === $count ) {
                    $words = preg_split( '/\s+/', $p );
                    $out[] = implode( ' ', array_slice( $words, 0, $max_words ) );
                }
                break;
            }
            $out[] = $p;
            $count += $w;
        }
        return implode( ', ', $out );
    }

    /* ── 간단 HEX 색상 검증 (형식만 확인, 실패 시 빈 문자열) ── */
    private function sanitize_hex_color_soft( $val ) {
        $val = trim( (string) $val );
        if ( '' === $val ) return '';
        if ( '#' !== substr( $val, 0, 1 ) ) $val = '#' . $val;
        return preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $val ) ? $val : '';
    }

    /* ── 고품질 폴백 프롬프트 빌더 (Gemini 프롬프트 생성 실패 시 사용, FLUX 자연어 형식) ── */
    private function build_fallback_horde_prompt( $topic, $style, $dir, $research = [] ) {
        $visual    = ! empty( $research['visual_context'] ) ? $research['visual_context'] : $this->topic_to_visual_concept( $topic );
        $hero      = ! empty( $research['hero_shot'] )      ? $research['hero_shot']      : '';
        $color     = ! empty( $research['color_mood'] )     ? $research['color_mood']     : '';
        $kv_arr    = ! empty( $research['key_visuals'] )    ? (array) $research['key_visuals'] : [];
        $kv        = implode( ', ', array_slice( $kv_arr, 0, 3 ) );

        $subject   = $hero ?: $visual;
        $kv_str    = $kv ? ' featuring ' . $kv . ',' : '';
        $color_str = $color ? ' in ' . $color . ',' : '';

        // ⚠️ 2026-08-09: 부정문 나열 방식 폐기 — 위 ajax_generate_image_prompt()와
        // 동일한 이유(확산 모델은 "no X"를 이해하지 못하고 X를 오히려 그려 넣음).
        // "없어야 할 것"을 나열하는 대신 "있어야 할 것"만 긍정 서술로 명시한다.

        // ⚠️ 2026-08: 화면/모니터 언급은 주제가 실제로 기기/앱/웹서비스를 다룰 때만 넣는다
        // (그 외 주제에 무조건 삽입하면 FLUX가 컴퓨터를 습관적으로 그려 넣는 역효과가 있었음).
        $is_device_topic = $this->topic_mentions_device_or_screen( $topic, $subject, $kv );

        $subject_only_phrase = ( $style !== 'photo_realistic' )
            ? 'the sole visual focus is entirely on abstract shapes, objects, and graphic elements, '
            : '';

        if ( $style === 'photo_realistic' ) {
            $surface_phrase = 'rendered so that any signs, papers, screens, or labels visible in the scene appear as smooth, blank, unmarked surfaces with soft natural reflections, ';
            if ( $is_device_topic ) {
                $surface_phrase .= 'any screen or display in the scene shows a soft, softly-lit blank glow, ';
            }
            $tail = $surface_phrase . 'wide 16:9 composition';
        } else {
            $surface_phrase = 'rendered so that all icons and shapes are clean solid graphic outlines and abstract forms on smooth, blank, unmarked surfaces, ';
            if ( $is_device_topic ) {
                $surface_phrase .= 'any screen or display shows only a soft, softly-lit blank glow, ';
            }
            $tail = $surface_phrase
                    . $subject_only_phrase
                    . 'with a large open plain area kept clean and empty at the center, reserved for a future title overlay, wide 16:9 composition';
        }

        $raw_prompt = "A masterpiece-quality image of {$subject},{$kv_str}{$color_str} {$dir['core']}, "
                . "in a {$dir['sdxl_style']} look, {$tail}";
        $prompt = $this->truncate_prompt_by_words( $this->strip_weight_syntax( $raw_prompt ), 90 );

        return [ 'prompt' => $prompt, 'neg_prompt' => '' ];
    }

    private function build_local_image_prompt( $topic, $style, $dir, $visual_context ) {
        $topic = sanitize_text_field( $topic );
        $style_label = isset( $dir['label'] ) ? $dir['label'] : ucfirst( $style );
        $core = isset( $dir['core'] ) ? $dir['core'] : '';

        $prompt = 'Create a ' . strtolower( $style_label ) . ' thumbnail image for "' . $topic . '" using ' . trim( $dir['sdxl_style'] );
        if ( $core !== '' ) {
            $prompt .= ', ' . $core;
        }
        $prompt .= '. Represent the topic as ' . trim( $visual_context ) . '.';
        if ( $style !== 'photo_realistic' ) {
            $prompt .= ' The composition is entirely built from abstract shapes, objects, and graphic elements, with a large open plain area kept clean and empty at the center, reserved for future overlay text.';
        }
        // ⚠️ 2026-08-09: 부정문("No text, no letters...")을 폐기 — 확산 모델이 이를
        // 오히려 텍스트/글자를 그려 넣으라는 지시로 오인하는 사례가 실사용에서 확인됨.
        // 대신 표면 상태를 긍정문으로 직접 서술한다.
        $prompt .= ' All surfaces, signs, and icons appear smooth, blank, and unmarked, rendered as clean solid graphic outlines and plain colored shapes.';

        $prompt = $this->strip_weight_syntax( preg_replace( '/\s+/', ' ', trim( $prompt ) ) );
        return [
            'prompt'     => $prompt,
            'neg_prompt' => '',
        ];
    }



    /** Generate an image through the configured UseAPI Google Flow account. */
    public function ajax_google_flow_generate() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );
        $prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $model  = isset( $_POST['flow_model'] ) ? sanitize_key( wp_unslash( $_POST['flow_model'] ) ) : '';
        if ( '' === $prompt ) wp_send_json_error( [ 'message' => '이미지 프롬프트가 없습니다.' ] );
        if ( ! class_exists( 'Zorlinq32_UseAPI_Google_Flow' ) ) wp_send_json_error( [ 'message' => 'UseAPI Google Flow 모듈을 불러오지 못했습니다.' ] );
        $image = Zorlinq32_UseAPI_Google_Flow::generate_image( $prompt, $model );
        if ( is_wp_error( $image ) ) wp_send_json_error( [ 'message' => $image->get_error_message(), 'code' => $image->get_error_code() ] );
        $mime = preg_replace( '/;.*$/', '', (string) $image['mime'] );
        wp_send_json_success( [
            'provider' => 'useapi_google_flow',
            'data_url' => 'data:' . $mime . ';base64,' . base64_encode( $image['body'] ),
            'mime' => $mime,
            'model' => $image['model'],
            'size_kb' => round( strlen( $image['body'] ) / 1024 ),
        ] );
    }

    /* ── 한국어 주제 → 고품질 영어 시각 개념 (리서치 워커 완전 실패 시 최종 안전망 전용) ──
           ⚠️ 2026-08 수정: "삼성 갤럭시 폴드 사전예약"이 "사전예[약]"의 "약" 한 글자가
              '약|영양제|비타민' 패턴에 부분 문자열로 걸려 "알약(영양제) 사진"으로
              잘못 매핑되던 심각한 버그를 수정. 원인은 두 가지였다:
                1) 1~2글자 짧은 패턴이 단어 경계 없이 preg_match되어 무관한 단어 속
                   글자와 우연히 일치 (예: "예약"의 "약", "돈가스"의 "돈", "방법"의 "법").
                2) 삼성/갤럭시/아이폰/노트북 등 "전자기기·IT 제품" 카테고리 자체가
                   테이블에 없어서, 진짜 매칭되어야 할 곳이 없다 보니 엉뚱한 느슨한
                   패턴에 먼저 걸릴 수밖에 없는 구조였다.
           대응: (a) 위험한 1~2글자 키워드는 전부 \b 단어 경계 또는 완전어 매칭으로
                 전환, (b) 전자기기/브랜드 카테고리를 최우선 순위로 추가,
                 (c) 매칭 우선순위를 "더 구체적인 패턴 먼저"로 재정렬. */
    /* ── 주제가 실제로 "화면/기기/앱/웹서비스"를 다루는지 판정 ──
       화면·모니터·컴퓨터 관련 문구는 이 판정이 참일 때만 프롬프트에 포함시킨다.
       (그 외 모든 주제에서 화면 관련 언급을 완전히 배제해 "항상 배경에 컴퓨터가
       하나 있다"는 문제를 근본적으로 해결하기 위한 게이트 함수) */
    private function topic_mentions_device_or_screen( $topic, $actual_meaning = '', $visual_context = '' ) {
        $haystack = $topic . ' ' . $actual_meaning . ' ' . $visual_context;
        $pattern  = '/(스마트폰|아이폰|iphone|아이패드|ipad|맥북|macbook|갤럭시|galaxy|노트북|laptop|'
                  . '태블릿|tablet|모니터|monitor|스크린|screen|디스플레이|display|웹사이트|website|'
                  . '홈페이지|앱|app\b|application|어플|프로그램|소프트웨어|software|플랫폼|platform|'
                  . 'pc버전|pc\s*버전|다운로드|download|설치|install|업데이트|update|화면|컴퓨터|computer|'
                  . 'ui|ux|인터페이스|interface|채팅|chat|메신저|messenger|카카오톡|kakaotalk|줌|zoom)/iu';
        return (bool) preg_match( $pattern, $haystack );
    }

    private function topic_to_visual_concept( $topic ) {
        $map = [
            // ── 전자기기·IT 제품 (브랜드/기종명) — 다른 카테고리보다 항상 먼저 검사 ──
            '갤럭시|galaxy|아이폰|iphone|아이패드|ipad|맥북|macbook|삼성|애플|샤오미|화웨이'
                => 'sleek modern smartphone or tablet device on a minimalist studio surface, dramatic product photography lighting, reflective surface, premium tech product shot, sharp focus on the device',
            '노트북|laptop|태블릿|모니터|이어폰|에어팟|스마트워치|가전|가전제품'
                => 'premium consumer electronics product shot on clean studio background, dramatic rim lighting, modern minimalist tech aesthetic, sharp product focus',
            // ── 건강·피트니스 ──
            '다이어트|체중|살빼|감량|체지방'           => 'athletic woman running on scenic mountain trail at sunrise, dynamic motion blur, sports photography, fit healthy body, vibrant energy',
            '운동|헬스|피트니스|근력|트레이닝'         => 'modern gym interior with athlete lifting weights, dramatic low-angle shot, muscular silhouette against bright window, powerful composition',
            // ── 금융·재테크 ──
            '재테크|투자|주식|펀드|자산|포트폴리오'    => 'dramatic financial trading floor with glowing stock charts, gold bull statue, rising green graph arrows, wealth concept, dramatic lighting',
            '부동산|아파트|주택|청약'                  => 'stunning luxury apartment building exterior at dusk, warm interior lights glowing, modern architecture, real estate premium',
            '금융|경제|은행|대출'                      => 'professional financial concept, neat stacks of gold coins and paper bills, clean marble surface, wealth and prosperity visual',
            // ── 건강·의료 ──
            '건강|wellness|의학|병원|치료'             => 'bright modern hospital or wellness center, cheerful medical professional in white coat, clean hygienic atmosphere, hope and health',
            '영양제|비타민|supplement|\bpill\b'        => 'colorful vitamin supplements and capsules arranged artfully on white surface, health and vitality concept, macro photography',
            // ── 음식·요리 ──
            '요리|레시피|cooking|음식|맛집'            => 'exquisite gourmet food photography, beautifully plated dish with garnish, soft bokeh background, professional culinary art, appetite appeal',
            '카페|커피|coffee|베이커리'                => 'cozy artisan coffee shop, steaming latte art in ceramic cup, warm ambient lighting, inviting atmosphere, coffee culture aesthetic',
            // ── IT·기술(제품이 아닌 개념/서비스) ──
            'IT|소프트웨어|프로그래밍|코딩|개발'       => 'futuristic code on holographic screen, developer hands on glowing keyboard, dark room with blue neon code streams, tech aesthetic',
            'AI|인공지능|머신러닝|딥러닝'              => 'abstract neural network visualization, glowing blue synaptic connections in deep space, artificial intelligence concept art, stunning sci-fi',
            // ── 메신저·채팅 앱(브랜드/기종명보다는 뒤, 하지만 일반 "앱" 패턴보다는
            //    반드시 앞에 위치 — 더 구체적인 패턴이 먼저 매칭되어야 함).
            //    실제 브랜드 로고·UI를 재현하지 않고, 대화 말풍선을 연상시키는
            //    독창적이고 일반화된 그래픽 오브젝트로만 표현한다(상표권 보호). ──
            '카카오톡|카톡|kakaotalk|라인\b|line\s*app|왓츠앱|whatsapp|텔레그램|telegram|디스코드|discord|메신저|messenger|채팅앱|채팅\s*앱'
                => 'a large, softly rounded speech-bubble-shaped graphic object as the clear hero visual, glossy modern surface with a warm inviting glow, symbolizing instant messaging and connection, clean commercial app-branding photography style, no real logos or wordmarks',
            'pc버전|pc\s*버전|pc용|윈도우용|windows용'
                => 'a sleek modern laptop or desktop monitor shown at a dynamic angle with a warm glowing screen bezel (screen content left abstract/blank), paired with a bold download-arrow-shaped graphic accent, premium software product photography',
            '앱|모바일|어플리케이션|어플'              => 'modern smartphone with glowing app interface, floating holographic UI elements, clean tech product photography',
            // ── 교육·학습 ──
            '교육|학습|공부|study|강의|수업'           => 'bright modern classroom or study space, focused student with open books and laptop, golden hour light, academic achievement atmosphere',
            '자격증|합격|취업준비'                     => 'triumphant graduate holding diploma, confetti celebration, achievement and success concept, warm joyful lighting',
            // ── 여행 ──
            '여행|관광|trip|해외여행|여행지'           => 'breathtaking travel destination panorama, dramatic landscape with vibrant sky, adventure and exploration photography, wanderlust',
            // ── 라이프스타일·뷰티 ──
            '뷰티|화장품|스킨케어|beauty'              => 'luxury beauty product flatlay, premium skincare bottles on marble, fresh flowers, elegant editorial photography, glowing radiant skin',
            '패션|fashion|트렌드'                      => 'high-fashion editorial photography, stylish model in bold outfit, clean studio background, Vogue-quality composition',
            '반려동물|강아지|고양이|\bpet\b'           => 'adorable golden retriever in sunlit park, joyful expression, soft bokeh, warm natural light, heartwarming companion photography',
            // ── 비즈니스 ──
            '창업|스타트업|마케팅|비즈니스'            => 'dynamic startup office, diverse team collaborating around modern workspace, energy and innovation atmosphere, entrepreneurship',
            '취업|직장|커리어|채용|면접'               => 'confident professional in business attire, modern office skyline view, success and career achievement concept, aspirational',
            // ── 환경·사회 ──
            '환경|자연환경|eco|기후|생태'              => 'stunning pristine natural landscape, lush green forest with morning mist, environmental conservation beauty, Earth appreciation',
            '지원금|복지정책|공공서비스'               => 'clean government building exterior with flag, professional civic architecture, public service and community concept',
            // ── 엔터테인먼트 ──
            '게임|gaming|e스포츠'                     => 'dramatic esports arena with glowing RGB gaming setup, intense player at computer, neon lighting, competitive gaming atmosphere',
            '영화|드라마|스트리밍'                     => 'cinematic film reel or movie clapperboard, dramatic lighting, Hollywood glamour concept, entertainment industry',
            '음악|music|아이돌'                        => 'dynamic music performance, musician on stage with dramatic backlighting, concert atmosphere, passion for music',
            // ── 법·금융 서비스 ──
            '법률사무소|법률상담|계약서|보험'          => 'professional law office, scales of justice on mahogany desk, leather-bound books, authority and trustworthiness concept',
            '세금|회계|절세|신고'                      => 'clean professional financial documents and calculator, organized tax concept, precision and accuracy visual',
            // ── 여행·교통·티켓 특가 ──
            '기차|열차|ktx|srt|레일카드|철도|기차표|열차표'
                => 'a sleek modern high-speed train pulling into or speeding past a scenic station platform, dynamic motion, travel photography, golden hour light on the train exterior, sense of journey and destination',
            '항공권|비행기표|항공|여행특가|땡처리|얼리버드'
                => 'an airplane wing view over dramatic clouds at sunset, or a boarding gate scene with warm travel-adventure lighting, wanderlust travel photography',
            '숙소|호텔|펜션|리조트|에어비앤비'
                => 'a beautifully lit hotel room or resort exterior at dusk, inviting warm interior lights, luxury hospitality photography',
        ];
        foreach ( $map as $pattern => $concept ) {
            // \b가 이미 패턴 안에 포함된 경우(pill, pet)는 그대로 두고,
            // 그 외 한글/영문 키워드는 앞뒤에 다른 한글 음절이 붙어 있어도 오매칭되지
            // 않도록 부분 단어 경계를 적용한다 (예: "예약"의 "약"이 "약"에 안 걸리게).
            $safe_pattern = $pattern;
            if ( strpos( $pattern, '\b' ) === false ) {
                // 한글은 PCRE \b가 정확히 동작하지 않으므로, 대신 각 대안 앞뒤에
                // 다른 한글 음절이 직접 붙어있지 않은지 부정형 전후방탐색으로 확인한다.
                $alts = explode( '|', $pattern );
                $alts = array_map( function( $alt ) {
                    return '(?<![가-힣])' . $alt . '(?![가-힣])';
                }, $alts );
                $safe_pattern = implode( '|', $alts );
            }
            if ( preg_match( '/' . $safe_pattern . '/iu', $topic ) ) return $concept;
        }

        /* ⚠️ 2026-08 수정 (2차): 위 map 어디에도 안 걸리는 주제가 예전엔
           "avoiding generic stock-icon clichés such as a plain computer monitor
           with a chat bubble and a download arrow on a generic purple-blue
           gradient background" 라는 문구로 떨어졌었다. 이 문장이 바로 그 결과물
           (컴퓨터 모니터 + 채팅 말풍선 + 다운로드 화살표 + 보라색 그라디언트)을
           단어 하나하나 그대로 묘사하고 있었기 때문에, "피하라"는 부정문임에도
           불구하고 FLUX가 그 구체적인 명사들을 그대로 그려버리는 결과를 낳았다
           (실제 사례: "연중 영국 기차 및 레일카드 특가" 같은 여행 주제에서
           아이맥 컴퓨터+채팅 아이콘+다운로드 화살표가 나온 버그가 바로 이것).
           대응: 부정형 "이런 걸 피하라" 문구를 완전히 삭제하고, 대신 원본
           키워드에서 실제 존재하는 명사(사물/장소/행위)를 뽑아 "이것을 그려라"는
           긍정형 지시로만 구성한다. 어떤 상황에서도 컴퓨터/모니터/채팅/다운로드
           같은 단어 자체가 이 함수의 출력에 등장하지 않도록 보장한다. */
        $clean = preg_replace( '/[^\w\s가-힣]/u', ' ', $topic );
        $clean = preg_replace( '/\s+/u', ' ', trim( $clean ) );
        $clean = mb_substr( $clean, 0, 60, 'UTF-8' );
        return 'a specific, concrete real-world photographic scene that directly and unmistakably depicts what "' . $clean . '" '
            . 'is actually about — identify the real object, place, product, or activity this phrase names, and render '
            . 'exactly that as the single dominant subject, photographed or illustrated with professional studio or '
            . 'on-location composition, vivid accurate colors, natural authentic materials and textures, and a clear '
            . 'single subject in sharp focus, award-winning photography or illustration quality';
    }

            /* ── AJAX: 템플릿 저장 ── */
    public function ajax_save_template() {
        check_ajax_referer( 'zorlinq32_ai_template_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $name          = isset( $_POST['name'] )          ? sanitize_text_field( $_POST['name'] ) : '';
        $title_x = isset( $_POST['title_x'] ) ? (float) $_POST['title_x'] : 0.05;
        $title_y = isset( $_POST['title_y'] ) ? (float) $_POST['title_y'] : 0.55;
        $title_w = isset( $_POST['title_w'] ) ? (float) $_POST['title_w'] : 0.90;
        $title_h = isset( $_POST['title_h'] ) ? (float) $_POST['title_h'] : 0.25;
        $sub_x   = isset( $_POST['sub_x'] )   ? (float) $_POST['sub_x']   : 0.05;
        $sub_y   = isset( $_POST['sub_y'] )   ? (float) $_POST['sub_y']   : 0.80;
        $sub_w   = isset( $_POST['sub_w'] )   ? (float) $_POST['sub_w']   : 0.90;
        $sub_h   = isset( $_POST['sub_h'] )   ? (float) $_POST['sub_h']   : 0.12;

        if ( ! $attachment_id || empty( $name ) )
            wp_send_json_error( [ 'message' => '이미지와 스타일명을 입력하세요.' ] );

        $preview_url = wp_get_attachment_url( $attachment_id );
        $templates   = get_option( 'zorlinq32_ai_thumb_templates', [] );

        $edit_idx = isset( $_POST['edit_idx'] ) && $_POST['edit_idx'] !== '' ? (int) $_POST['edit_idx'] : null;

        $entry = compact( 'attachment_id', 'name', 'preview_url',
                          'title_x', 'title_y', 'title_w', 'title_h',
                          'sub_x', 'sub_y', 'sub_w', 'sub_h' );

        if ( $edit_idx !== null && isset( $templates[ $edit_idx ] ) ) {
            $templates[ $edit_idx ] = $entry;
            $msg = '템플릿이 수정되었습니다.';
        } else {
            $templates[] = $entry;
            $msg = '템플릿이 저장되었습니다.';
        }
        update_option( 'zorlinq32_ai_thumb_templates', $templates );
        wp_send_json_success( [ 'message' => $msg, 'templates' => $templates ] );
    }

    /* ── AJAX: 템플릿 삭제 ── */
    public function ajax_delete_template() {
        check_ajax_referer( 'zorlinq32_ai_template_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );
        $idx       = isset( $_POST['idx'] ) ? (int) $_POST['idx'] : -1;
        $templates = get_option( 'zorlinq32_ai_thumb_templates', [] );
        if ( ! isset( $templates[ $idx ] ) ) wp_send_json_error( [ 'message' => '존재하지 않는 템플릿입니다.' ] );
        array_splice( $templates, $idx, 1 );
        update_option( 'zorlinq32_ai_thumb_templates', $templates );
        wp_send_json_success( [ 'message' => '삭제되었습니다.', 'templates' => $templates ] );
    }

    /* ════════════════════════════════════════════════════════
       2026-08 추가: 사용자 정의(커스텀) AI 이미지 스타일 관리
       - 기존 5개 고정 스타일(포스터/미니멀/사실적 사진/타이포그래피/브랜딩)은
         건드리지 않고, 그 5개 중 하나를 "base"로 삼아 프롬프트 문구
         (원칙/주제 표현법/품질 표현/회피 요소)만 사용자가 직접 편집할 수
         있는 새로운 스타일을 추가·삭제·수정하는 기능이다.
       - base를 반드시 지정하게 함으로써:
         1) 텍스트 오버레이 레이아웃(getTextConfig)이 항상 유효한 5개 중
            하나로 폴백되어 JS 쪽 오류가 발생하지 않는다.
         2) 커스텀 스타일도 기존의 검증된 레이아웃을 재사용한다.
       - 옵션명: zorlinq32_ai_custom_styles (배열의 배열, id 기준 map 아님 —
         list 형태로 저장하고 조회 시 id → 항목 맵으로 변환해 사용한다)
    ════════════════════════════════════════════════════════ */

    /** 저장된 커스텀 스타일 목록(list 형태)을 그대로 반환 */
    private function get_custom_ai_styles_list() {
        $list = get_option( 'zorlinq32_ai_custom_styles', [] );
        return is_array( $list ) ? $list : [];
    }

    /** 커스텀 스타일을 id => 정의 맵으로 변환해 반환 (프롬프트 생성 로직에서 조회용) */
    private function get_custom_ai_styles() {
        $map = [];
        foreach ( $this->get_custom_ai_styles_list() as $item ) {
            if ( ! empty( $item['id'] ) ) {
                $map[ $item['id'] ] = $item;
            }
        }
        return $map;
    }

    /** 'custom_style_' + 랜덤 문자열 형태의 고유 id 생성 (충돌 시 재시도) */
    private function generate_custom_style_id( $existing_ids ) {
        do {
            $id = 'custom_style_' . substr( md5( uniqid( '', true ) ), 0, 10 );
        } while ( in_array( $id, $existing_ids, true ) );
        return $id;
    }

    /* ── AJAX: 커스텀 AI 스타일 저장(추가/수정) ── */
    public function ajax_save_custom_ai_style() {
        check_ajax_referer( 'zorlinq32_ai_template_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        $name      = isset( $_POST['name'] )      ? sanitize_text_field( wp_unslash( $_POST['name'] ) )      : '';
        $base      = isset( $_POST['base'] )      ? sanitize_text_field( wp_unslash( $_POST['base'] ) )      : 'poster';
        $principle = isset( $_POST['principle'] ) ? sanitize_textarea_field( wp_unslash( $_POST['principle'] ) ) : '';
        $subject   = isset( $_POST['subject'] )   ? sanitize_textarea_field( wp_unslash( $_POST['subject'] ) )   : '';
        $quality   = isset( $_POST['quality'] )   ? sanitize_textarea_field( wp_unslash( $_POST['quality'] ) )   : '';
        $avoid     = isset( $_POST['avoid'] )     ? sanitize_textarea_field( wp_unslash( $_POST['avoid'] ) )     : '';
        $edit_id   = isset( $_POST['edit_id'] )   ? sanitize_text_field( wp_unslash( $_POST['edit_id'] ) )       : '';

        if ( empty( $name ) )
            wp_send_json_error( [ 'message' => '스타일 이름을 입력하세요.' ] );

        // base는 반드시 5개 기본 스타일 중 하나여야 함(화이트리스트) — 임의값이 들어오면
        // 텍스트 오버레이/Worker 라우팅이 깨질 수 있으므로 안전하게 poster로 강제 폴백.
        $allowed_bases = [ 'poster', 'minimal', 'photo_realistic', 'typography', 'branding' ];
        if ( ! in_array( $base, $allowed_bases, true ) ) {
            $base = 'poster';
        }

        $list = $this->get_custom_ai_styles_list();
        $existing_ids = wp_list_pluck( $list, 'id' );

        $entry = [
            'id'        => '',
            'name'      => $name,
            'base'      => $base,
            'principle' => $principle,
            'subject'   => $subject,
            'quality'   => $quality,
            'avoid'     => $avoid,
        ];

        $found_idx = null;
        if ( $edit_id !== '' ) {
            foreach ( $list as $idx => $item ) {
                if ( isset( $item['id'] ) && $item['id'] === $edit_id ) { $found_idx = $idx; break; }
            }
        }

        if ( null !== $found_idx ) {
            $entry['id'] = $edit_id;
            $list[ $found_idx ] = $entry;
            $msg = '스타일이 수정되었습니다.';
        } else {
            $entry['id'] = $this->generate_custom_style_id( $existing_ids );
            $list[] = $entry;
            $msg = '스타일이 추가되었습니다.';
        }

        update_option( 'zorlinq32_ai_custom_styles', $list );
        wp_send_json_success( [ 'message' => $msg, 'styles' => $list ] );
    }

    /* ── AJAX: 커스텀 AI 스타일 삭제 ── */
    public function ajax_delete_custom_ai_style() {
        check_ajax_referer( 'zorlinq32_ai_template_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        $id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        $list = $this->get_custom_ai_styles_list();

        $new_list = [];
        $removed  = false;
        foreach ( $list as $item ) {
            if ( isset( $item['id'] ) && $item['id'] === $id ) { $removed = true; continue; }
            $new_list[] = $item;
        }

        if ( ! $removed ) wp_send_json_error( [ 'message' => '존재하지 않는 스타일입니다.' ] );

        update_option( 'zorlinq32_ai_custom_styles', array_values( $new_list ) );
        wp_send_json_success( [ 'message' => '삭제되었습니다.', 'styles' => array_values( $new_list ) ] );
    }

    /* ── 템플릿 관리 설정 페이지 ── */
    public function render_template_manager() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // 미디어 업로더 스크립트 강제 로드 (hook 이름 불일치 방지용 이중 호출)
        wp_enqueue_media();

        $templates      = get_option( 'zorlinq32_ai_thumb_templates', [] );
        $nonce          = wp_create_nonce( 'zorlinq32_ai_template_nonce' );
        $custom_styles  = $this->get_custom_ai_styles_list();
        $base_style_labels = [
            'poster'          => '포스터',
            'minimal'         => '미니멀',
            'photo_realistic' => '사실적 사진',
            'typography'      => '타이포그래피',
            'branding'        => '브랜딩',
        ];
        ?>
        <div class="wrap zorlinq32-wrap">
            <?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

            <h2>🎨 AI 이미지 스타일 관리</h2>
            <p class="zorlinq32-help-text">기존 5개 기본 스타일(포스터/미니멀/사실적 사진/타이포그래피/브랜딩)은 그대로 유지되며, 아래에서 그 5개 중 하나를 기반(base)으로 삼아 프롬프트 문구만 직접 커스터마이징한 나만의 스타일을 추가할 수 있습니다. 기반 스타일의 구도·배경·조명 조합과 텍스트 오버레이 레이아웃은 그대로 상속되므로 항상 안전하게 동작합니다.</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;max-width:1100px;margin-bottom:36px;">
                <!-- 왼쪽: 커스텀 스타일 등록 폼 -->
                <div style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h2 style="margin-top:0;font-size:16px;">새 스타일 추가 / 수정</h2>
                    <input type="hidden" id="zorlinq32-ai-style-edit-id" value="">

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">1. 스타일 이름</label>
                        <input type="text" id="zorlinq32-ai-style-name" placeholder="예: 파스텔 브랜딩"
                               style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">2. 기반(base) 스타일 <small style="color:#999;">(구도·배경·조명 조합 및 텍스트 배치를 그대로 상속)</small></label>
                        <select id="zorlinq32-ai-style-base" style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;">
                            <?php foreach ( $base_style_labels as $bkey => $blabel ) : ?>
                                <option value="<?php echo esc_attr( $bkey ); ?>"><?php echo esc_html( $blabel ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">3. 원칙(principle) <small style="color:#999;">(비워두면 기반 스타일 값 사용)</small></label>
                        <textarea id="zorlinq32-ai-style-principle" rows="3" placeholder="이 스타일이 전체적으로 어떤 느낌이어야 하는지 (영어 권장)"
                                  style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;"></textarea>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">4. 주제 표현법(subject) <small style="color:#999;">(비워두면 기반 스타일 값 사용)</small></label>
                        <textarea id="zorlinq32-ai-style-subject" rows="2" placeholder="주제(히어로 오브젝트)를 어떻게 그릴지"
                                  style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;"></textarea>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">5. 품질 표현(quality) <small style="color:#999;">(비워두면 기반 스타일 값 사용)</small></label>
                        <input type="text" id="zorlinq32-ai-style-quality" placeholder="예: soft pastel airbrush finish, gentle editorial polish"
                               style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">6. 회피 요소(avoid) <small style="color:#999;">(비워두면 기반 스타일 값 사용)</small></label>
                        <textarea id="zorlinq32-ai-style-avoid" rows="2" placeholder="이 스타일에서 절대 나오면 안 되는 요소"
                                  style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;"></textarea>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="button" id="zorlinq32-ai-save-style-btn" class="button button-primary">💾 스타일 저장</button>
                        <button type="button" id="zorlinq32-ai-reset-style-btn" class="button">↺ 초기화</button>
                    </div>
                    <p id="zorlinq32-ai-style-form-msg" style="margin:10px 0 0;font-size:13px;min-height:20px;"></p>
                </div>

                <!-- 오른쪽: 저장된 커스텀 스타일 목록 -->
                <div>
                    <h2 style="font-size:16px;margin-top:0;">저장된 커스텀 스타일 (<span id="zorlinq32-ai-style-count"><?php echo count( $custom_styles ); ?></span>개)</h2>
                    <div id="zorlinq32-ai-style-list">
                    <?php if ( empty( $custom_styles ) ) : ?>
                        <p style="color:#999;font-size:13px;" id="zorlinq32-ai-style-empty-msg">아직 추가된 커스텀 스타일이 없습니다.</p>
                    <?php else : ?>
                        <?php foreach ( $custom_styles as $s ) :
                            $base_label = isset( $base_style_labels[ $s['base'] ] ) ? $base_style_labels[ $s['base'] ] : $s['base'];
                        ?>
                            <div class="zorlinq32-ai-style-card" data-id="<?php echo esc_attr( $s['id'] ); ?>"
                                 style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin-bottom:12px;display:flex;gap:12px;align-items:center;">
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $s['name'] ); ?></div>
                                    <div style="font-size:11px;color:#888;margin-top:2px;">기반: <?php echo esc_html( $base_label ); ?></div>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                                    <button class="button button-small zorlinq32-ai-style-edit-btn" data-id="<?php echo esc_attr( $s['id'] ); ?>">✏️ 수정</button>
                                    <button class="button button-small zorlinq32-ai-style-del-btn" data-id="<?php echo esc_attr( $s['id'] ); ?>"
                                            style="color:#d32f2f;border-color:#d32f2f;">🗑 삭제</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <hr style="border:none;border-top:2px solid #f0f0f0;margin:0 0 28px;max-width:1100px;">

            <h2>🖼️ AI 썸네일 템플릿 관리</h2>
            <p class="zorlinq32-help-text">템플릿 이미지를 업로드하고, 제목/부제목이 표시될 위치를 드래그로 지정한 뒤 저장하세요.</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;max-width:1100px;margin-top:20px;">

                <!-- 왼쪽: 등록 폼 -->
                <div style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h2 style="margin-top:0;font-size:16px;">새 템플릿 추가 / 수정</h2>
                    <input type="hidden" id="zorlinq32-ai-edit-idx" value="">

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">1. 스타일명</label>
                        <input type="text" id="zorlinq32-ai-tpl-name" placeholder="예: 파란 배경 스타일"
                               style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">2. 템플릿 이미지 업로드</label>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <button type="button" id="zorlinq32-ai-upload-btn" class="button button-secondary">📁 미디어 라이브러리에서 선택</button>
                            <label class="button button-secondary" style="cursor:pointer;margin:0;">
                                ⬆️ 직접 업로드
                                <input type="file" id="zorlinq32-ai-file-input" accept="image/*"
                                       style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
                            </label>
                            <span id="zorlinq32-ai-upload-name" style="font-size:12px;color:#666;width:100%;margin-top:4px;">선택된 파일 없음</span>
                        </div>
                        <input type="hidden" id="zorlinq32-ai-attachment-id" value="">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">3. 텍스트 위치 지정 (드래그)</label>
                        <p style="font-size:12px;color:#666;margin:0 0 8px;">
                            이미지 위에서 <span style="color:#1a73e8;font-weight:700;">파란 박스(제목)</span>와
                            <span style="color:#e53935;font-weight:700;">빨간 박스(부제목)</span>를 드래그하여 위치를 조정하세요.
                        </p>
                        <div id="zorlinq32-ai-canvas-wrap" style="position:relative;border:2px dashed #ccd0d4;border-radius:4px;background:#f5f5f5;min-height:120px;overflow:hidden;user-select:none;">
                            <img id="zorlinq32-ai-preview-img" src="" alt="" style="width:100%;display:none;pointer-events:none;">
                            <div id="zorlinq32-ai-title-rect" class="zorlinq32-ai-rect" data-type="title"
                                 style="display:none;position:absolute;background:rgba(26,115,232,.25);border:2px solid #1a73e8;cursor:move;box-sizing:border-box;border-radius:4px;min-width:40px;min-height:24px;">
                                <div class="zorlinq32-ai-rect-label" style="font-size:10px;font-weight:700;color:#1a73e8;padding:2px 4px;white-space:nowrap;">제목</div>
                                <div class="zorlinq32-ai-resize-handle" style="position:absolute;right:0;bottom:0;width:12px;height:12px;background:#1a73e8;cursor:se-resize;border-radius:2px 0 2px 0;"></div>
                            </div>
                            <div id="zorlinq32-ai-sub-rect" class="zorlinq32-ai-rect" data-type="sub"
                                 style="display:none;position:absolute;background:rgba(229,57,53,.2);border:2px solid #e53935;cursor:move;box-sizing:border-box;border-radius:4px;min-width:40px;min-height:18px;">
                                <div class="zorlinq32-ai-rect-label" style="font-size:10px;font-weight:700;color:#e53935;padding:2px 4px;white-space:nowrap;">부제목</div>
                                <div class="zorlinq32-ai-resize-handle" style="position:absolute;right:0;bottom:0;width:12px;height:12px;background:#e53935;cursor:se-resize;border-radius:2px 0 2px 0;"></div>
                            </div>
                        </div>
                        <!-- 히든 필드 (비율 저장) -->
                        <input type="hidden" id="f-title-x" value="0.05"><input type="hidden" id="f-title-y" value="0.55">
                        <input type="hidden" id="f-title-w" value="0.90"><input type="hidden" id="f-title-h" value="0.25">
                        <input type="hidden" id="f-sub-x"   value="0.05"><input type="hidden" id="f-sub-y"   value="0.80">
                        <input type="hidden" id="f-sub-w"   value="0.90"><input type="hidden" id="f-sub-h"   value="0.12">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;margin-bottom:6px;">4. 폰트 파일 경로 <small style="color:#999;">(선택 — TTF 절대경로)</small></label>
                        <input type="text" id="zorlinq32-ai-font-path"
                               value="<?php echo esc_attr( get_option( 'zorlinq32_ai_thumb_font_path', '' ) ); ?>"
                               placeholder="/path/to/font.ttf"
                               style="width:100%;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;box-sizing:border-box;">
                        <p style="font-size:11px;color:#888;margin:4px 0 0;">서버에 나눔고딕 등 한국어 TTF 폰트가 있으면 입력하세요. 없으면 GD 기본 폰트를 사용합니다.</p>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="button" id="zorlinq32-ai-save-tpl-btn" class="button button-primary">💾 템플릿 저장</button>
                        <button type="button" id="zorlinq32-ai-reset-form-btn" class="button">↺ 초기화</button>
                    </div>
                    <p id="zorlinq32-ai-form-msg" style="margin:10px 0 0;font-size:13px;min-height:20px;"></p>
                </div>

                <!-- 오른쪽: 저장된 템플릿 목록 -->
                <div>
                    <h2 style="font-size:16px;margin-top:0;">저장된 템플릿 (<?php echo count($templates); ?>개)</h2>
                    <div id="zorlinq32-ai-template-list">
                    <?php if ( empty($templates) ) : ?>
                        <p style="color:#999;font-size:13px;">아직 저장된 템플릿이 없습니다.</p>
                    <?php else : ?>
                        <?php foreach ( $templates as $i => $t ) : ?>
                            <div class="zorlinq32-ai-tpl-card" id="zorlinq32-ai-card-<?php echo $i; ?>"
                                 style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin-bottom:12px;display:flex;gap:12px;align-items:center;">
                                <?php if ( ! empty( $t['preview_url'] ) ) : ?>
                                    <img src="<?php echo esc_url( $t['preview_url'] ); ?>" alt=""
                                         style="width:90px;height:50px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                                <?php endif; ?>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $t['name'] ); ?></div>
                                    <div style="font-size:11px;color:#888;margin-top:2px;">
                                        제목 위치: (<?php printf('%.0f%%,%.0f%%', $t['title_x']*100, $t['title_y']*100); ?>) /
                                        부제목: (<?php printf('%.0f%%,%.0f%%', $t['sub_x']*100, $t['sub_y']*100); ?>)
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                                    <button class="button button-small zorlinq32-ai-edit-btn" data-idx="<?php echo $i; ?>">✏️ 수정</button>
                                    <button class="button button-small zorlinq32-ai-del-btn"  data-idx="<?php echo $i; ?>"
                                            style="color:#d32f2f;border-color:#d32f2f;">🗑 삭제</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var ajaxUrl = '<?php echo esc_js( admin_url("admin-ajax.php") ); ?>';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var tplData = <?php echo wp_json_encode( $templates ); ?>;

            /* ══════════════════════════════════════════════
               2026-08 추가: 커스텀 AI 이미지 스타일 관리 (추가/수정/삭제)
               — 기존 템플릿 관리 스크립트와 완전히 분리된 독립 블록으로
                 작성해 서로의 동작에 영향을 주지 않도록 했다.
            ══════════════════════════════════════════════ */
            var baseStyleLabels = <?php echo wp_json_encode( $base_style_labels ); ?>;
            // 현재 화면에 표시된 커스텀 스타일 목록(수정 버튼 클릭 시 참조용) — 저장/삭제 응답으로 계속 갱신.
            // (다른 함수들보다 먼저 선언해, 아래 클릭 핸들러들이 읽을 때 항상 최신 배열을 참조하게 한다.)
            var currentStyles = <?php echo wp_json_encode( $custom_styles ); ?>;

            function resetStyleForm() {
                $('#zorlinq32-ai-style-edit-id').val('');
                $('#zorlinq32-ai-style-name').val('');
                $('#zorlinq32-ai-style-base').val('poster');
                $('#zorlinq32-ai-style-principle').val('');
                $('#zorlinq32-ai-style-subject').val('');
                $('#zorlinq32-ai-style-quality').val('');
                $('#zorlinq32-ai-style-avoid').val('');
                $('#zorlinq32-ai-style-form-msg').text('');
            }

            function renderStyleList(styles) {
                var $list = $('#zorlinq32-ai-style-list');
                $('#zorlinq32-ai-style-count').text(styles.length);
                if (!styles.length) {
                    $list.html('<p style="color:#999;font-size:13px;" id="zorlinq32-ai-style-empty-msg">아직 추가된 커스텀 스타일이 없습니다.</p>');
                    return;
                }
                var html = '';
                styles.forEach(function(s) {
                    var baseLabel = baseStyleLabels[s.base] || s.base;
                    html += '<div class="zorlinq32-ai-style-card" data-id="' + s.id + '" ' +
                        'style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin-bottom:12px;display:flex;gap:12px;align-items:center;">' +
                        '<div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(s.name) + '</div>' +
                        '<div style="font-size:11px;color:#888;margin-top:2px;">기반: ' + escHtml(baseLabel) + '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">' +
                        '<button class="button button-small zorlinq32-ai-style-edit-btn" data-id="' + s.id + '">✏️ 수정</button>' +
                        '<button class="button button-small zorlinq32-ai-style-del-btn" data-id="' + s.id + '" style="color:#d32f2f;border-color:#d32f2f;">🗑 삭제</button>' +
                        '</div></div>';
                });
                $list.html(html);
            }

            function escHtml(str) {
                return $('<div>').text(str == null ? '' : str).html();
            }

            $('#zorlinq32-ai-reset-style-btn').on('click', function(e) {
                e.preventDefault();
                resetStyleForm();
            });

            $('#zorlinq32-ai-save-style-btn').on('click', function(e) {
                e.preventDefault();
                var name = $.trim($('#zorlinq32-ai-style-name').val());
                if (!name) {
                    $('#zorlinq32-ai-style-form-msg').css('color', '#d32f2f').text('스타일 이름을 입력하세요.');
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#zorlinq32-ai-style-form-msg').css('color', '#666').text('저장 중...');

                $.post(ajaxUrl, {
                    action:     'zorlinq32_ai_save_custom_style',
                    nonce:      nonce,
                    edit_id:    $('#zorlinq32-ai-style-edit-id').val(),
                    name:       name,
                    base:       $('#zorlinq32-ai-style-base').val(),
                    principle:  $('#zorlinq32-ai-style-principle').val(),
                    subject:    $('#zorlinq32-ai-style-subject').val(),
                    quality:    $('#zorlinq32-ai-style-quality').val(),
                    avoid:      $('#zorlinq32-ai-style-avoid').val()
                }).done(function(res) {
                    $btn.prop('disabled', false);
                    if (res && res.success) {
                        $('#zorlinq32-ai-style-form-msg').css('color', '#2e7d32').text(res.data.message || '저장되었습니다.');
                        currentStyles = res.data.styles || [];
                        renderStyleList(currentStyles);
                        resetStyleForm();
                    } else {
                        $('#zorlinq32-ai-style-form-msg').css('color', '#d32f2f').text((res && res.data && res.data.message) || '저장에 실패했습니다.');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $('#zorlinq32-ai-style-form-msg').css('color', '#d32f2f').text('네트워크 오류로 저장에 실패했습니다.');
                });
            });

            $(document).on('click', '.zorlinq32-ai-style-edit-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                // currentStyles는 페이지 로드 시 서버 렌더 데이터로 초기화되고,
                // 이후 저장/삭제가 성공할 때마다 서버 응답으로 계속 갱신되므로 항상 최신 상태다.
                var target = null;
                currentStyles.forEach(function(s) {
                    if (s.id === id) target = s;
                });
                if (!target) return;
                $('#zorlinq32-ai-style-edit-id').val(target.id);
                $('#zorlinq32-ai-style-name').val(target.name || '');
                $('#zorlinq32-ai-style-base').val(target.base || 'poster');
                $('#zorlinq32-ai-style-principle').val(target.principle || '');
                $('#zorlinq32-ai-style-subject').val(target.subject || '');
                $('#zorlinq32-ai-style-quality').val(target.quality || '');
                $('#zorlinq32-ai-style-avoid').val(target.avoid || '');
                $('#zorlinq32-ai-style-form-msg').text('');
                $('html, body').animate({ scrollTop: $('#zorlinq32-ai-style-name').offset().top - 100 }, 300);
            });

            $(document).on('click', '.zorlinq32-ai-style-del-btn', function(e) {
                e.preventDefault();
                if (!confirm('이 커스텀 스타일을 삭제하시겠습니까?')) return;
                var id = $(this).data('id');
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.post(ajaxUrl, {
                    action: 'zorlinq32_ai_delete_custom_style',
                    nonce:  nonce,
                    id:     id
                }).done(function(res) {
                    if (res && res.success) {
                        currentStyles = res.data.styles || [];
                        renderStyleList(currentStyles);
                    } else {
                        $btn.prop('disabled', false);
                        alert((res && res.data && res.data.message) || '삭제에 실패했습니다.');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    alert('네트워크 오류로 삭제에 실패했습니다.');
                });
            });

            /* ── 미디어 업로더 ──────────────────────── */
            var mediaFrame = null;

            $('#zorlinq32-ai-upload-btn').on('click', function(e) {
                e.preventDefault();

                // wp.media 사용 가능 여부 확인
                if ( typeof wp === 'undefined' || typeof wp.media === 'undefined' ) {
                    // Fallback: 숨겨진 파일 input으로 직접 업로드
                    $('#zorlinq32-ai-file-input').trigger('click');
                    return;
                }

                if ( mediaFrame ) {
                    mediaFrame.open();
                    return;
                }

                mediaFrame = wp.media({
                    title:    '템플릿 이미지 선택',
                    button:   { text: '이 이미지 사용' },
                    multiple: false,
                    library:  { type: 'image' }
                });

                mediaFrame.on('select', function() {
                    var att = mediaFrame.state().get('selection').first().toJSON();
                    $('#zorlinq32-ai-attachment-id').val(att.id);
                    $('#zorlinq32-ai-upload-name').text(att.filename || att.url.split('/').pop());
                    loadPreview(att.url);
                });

                mediaFrame.open();
            });

            /* ── Fallback: 파일 직접 업로드 ─────────── */
            $('#zorlinq32-ai-file-input').on('change', function() {
                var file = this.files[0];
                if (!file) return;

                $('#zorlinq32-ai-upload-name').text('업로드 중... ' + file.name);
                $('#zorlinq32-ai-form-msg').text('').css('color','#555');

                var formData = new FormData();
                formData.append('action',   'zorlinq32_ai_upload_template_image');
                formData.append('nonce',    nonce);
                formData.append('file',     file);

                $.ajax({
                    url:         ajaxUrl,
                    type:        'POST',
                    data:        formData,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        if (res.success) {
                            $('#zorlinq32-ai-attachment-id').val(res.data.attachment_id);
                            $('#zorlinq32-ai-upload-name').text(file.name);
                            loadPreview(res.data.url);
                            $('#zorlinq32-ai-form-msg').text('✅ 이미지 업로드 완료').css('color','#2e7d32');
                        } else {
                            $('#zorlinq32-ai-upload-name').text('업로드 실패');
                            $('#zorlinq32-ai-form-msg').text('❌ ' + (res.data && res.data.message ? res.data.message : '업로드 실패')).css('color','#d32f2f');
                        }
                    },
                    error: function() {
                        $('#zorlinq32-ai-upload-name').text('오류 발생');
                        $('#zorlinq32-ai-form-msg').text('❌ 서버 오류. 다시 시도해주세요.').css('color','#d32f2f');
                    }
                });
            });

            /* ── 미리보기 로드 ──────────────────────── */
            function loadPreview(url) {
                var $img = $('#zorlinq32-ai-preview-img');
                $img.attr('src', url).show();
                $img.off('load').on('load', function() {
                    initRects();
                });
                if ($img[0].complete && $img[0].naturalWidth) {
                    initRects();
                }
            }

            /* ── 드래그/리사이즈 박스 초기화 ─────────── */
            function initRects() {
                var $wrap = $('#zorlinq32-ai-canvas-wrap');
                var wW = $wrap.innerWidth();
                var wH = $('#zorlinq32-ai-preview-img').height() || (wW * 9 / 16);
                if (wH === 0) wH = wW * 9 / 16;
                $wrap.css('height', wH + 'px');

                ['#zorlinq32-ai-title-rect', '#zorlinq32-ai-sub-rect'].forEach(function(sel) {
                    var $r   = $(sel);
                    var type = $r.data('type');
                    var px = parseFloat(type === 'title' ? $('#f-title-x').val() : $('#f-sub-x').val());
                    var py = parseFloat(type === 'title' ? $('#f-title-y').val() : $('#f-sub-y').val());
                    var pw = parseFloat(type === 'title' ? $('#f-title-w').val() : $('#f-sub-w').val());
                    var ph = parseFloat(type === 'title' ? $('#f-title-h').val() : $('#f-sub-h').val());
                    $r.css({
                        left:   (px * wW) + 'px',
                        top:    (py * wH) + 'px',
                        width:  (pw * wW) + 'px',
                        height: (ph * wH) + 'px'
                    }).show();
                    makeDraggable($r, $wrap, wW, wH);
                    makeResizable($r, $wrap, wW, wH);
                });
            }

            function saveRectValues() {
                var $wrap = $('#zorlinq32-ai-canvas-wrap');
                var wW = $wrap.innerWidth();
                var wH = $wrap.height();
                if (!wW || !wH) return;
                var $t = $('#zorlinq32-ai-title-rect'), $s = $('#zorlinq32-ai-sub-rect');
                var tp = $t.position(), sp = $s.position();
                $('#f-title-x').val((tp.left / wW).toFixed(4));
                $('#f-title-y').val((tp.top  / wH).toFixed(4));
                $('#f-title-w').val(($t.width()  / wW).toFixed(4));
                $('#f-title-h').val(($t.height() / wH).toFixed(4));
                $('#f-sub-x').val((sp.left / wW).toFixed(4));
                $('#f-sub-y').val((sp.top  / wH).toFixed(4));
                $('#f-sub-w').val(($s.width()  / wW).toFixed(4));
                $('#f-sub-h').val(($s.height() / wH).toFixed(4));
            }

            function makeDraggable($r, $wrap) {
                var isDragging = false, startX, startY, origL, origT;
                $r.off('mousedown.drag').on('mousedown.drag', function(e) {
                    if ($(e.target).hasClass('zorlinq32-ai-resize-handle')) return;
                    isDragging = true;
                    startX = e.pageX; startY = e.pageY;
                    origL  = $r.position().left;
                    origT  = $r.position().top;
                    e.preventDefault();
                });
                $(document).off('mousemove.drag' + $r.attr('id'))
                    .on('mousemove.drag' + $r.attr('id'), function(e) {
                        if (!isDragging) return;
                        var wW = $wrap.innerWidth(), wH = $wrap.height();
                        var newL = Math.max(0, Math.min(origL + (e.pageX - startX), wW - $r.width()));
                        var newT = Math.max(0, Math.min(origT + (e.pageY - startY), wH - $r.height()));
                        $r.css({ left: newL + 'px', top: newT + 'px' });
                        saveRectValues();
                    });
                $(document).off('mouseup.drag' + $r.attr('id'))
                    .on('mouseup.drag' + $r.attr('id'), function() { isDragging = false; });
            }

            function makeResizable($r, $wrap) {
                var isResizing = false, startX, startY, origW, origH;
                $r.find('.zorlinq32-ai-resize-handle')
                    .off('mousedown.resize')
                    .on('mousedown.resize', function(e) {
                        isResizing = true;
                        startX = e.pageX; startY = e.pageY;
                        origW  = $r.width(); origH = $r.height();
                        e.preventDefault(); e.stopPropagation();
                    });
                $(document).off('mousemove.resize' + $r.attr('id'))
                    .on('mousemove.resize' + $r.attr('id'), function(e) {
                        if (!isResizing) return;
                        var wW = $wrap.innerWidth(), wH = $wrap.height();
                        var pos = $r.position();
                        var nw = Math.max(40, Math.min(origW + (e.pageX - startX), wW - pos.left));
                        var nh = Math.max(18, Math.min(origH + (e.pageY - startY), wH - pos.top));
                        $r.css({ width: nw + 'px', height: nh + 'px' });
                        saveRectValues();
                    });
                $(document).off('mouseup.resize' + $r.attr('id'))
                    .on('mouseup.resize' + $r.attr('id'), function() { isResizing = false; });
            }

            /* ── 저장 ──────────────────────────────── */
            $('#zorlinq32-ai-save-tpl-btn').on('click', function() {
                var name     = $('#zorlinq32-ai-tpl-name').val().trim();
                var attId    = $('#zorlinq32-ai-attachment-id').val();
                var editIdx  = $('#zorlinq32-ai-edit-idx').val();
                var fontPath = $('#zorlinq32-ai-font-path').val().trim();

                if (!name)  { $('#zorlinq32-ai-form-msg').text('스타일명을 입력하세요.').css('color','#d32f2f'); return; }
                if (!attId) { $('#zorlinq32-ai-form-msg').text('이미지를 선택/업로드하세요.').css('color','#d32f2f'); return; }

                $('#zorlinq32-ai-save-tpl-btn').prop('disabled', true).text('저장 중...');
                $('#zorlinq32-ai-form-msg').text('').css('color','#555');

                var postData = {
                    action:        'zorlinq32_ai_save_template',
                    nonce:         nonce,
                    name:          name,
                    attachment_id: attId,
                    edit_idx:      editIdx,
                    title_x: $('#f-title-x').val(), title_y: $('#f-title-y').val(),
                    title_w: $('#f-title-w').val(), title_h: $('#f-title-h').val(),
                    sub_x:   $('#f-sub-x').val(),   sub_y:   $('#f-sub-y').val(),
                    sub_w:   $('#f-sub-w').val(),   sub_h:   $('#f-sub-h').val()
                };

                if (fontPath) {
                    $.post(ajaxUrl, { action: 'zorlinq32_ai_save_font_path', nonce: nonce, font_path: fontPath });
                }

                $.post(ajaxUrl, postData, function(res) {
                    if (res.success) {
                        $('#zorlinq32-ai-form-msg').text('✅ ' + res.data.message).css('color','#2e7d32');
                        setTimeout(function() { location.reload(); }, 900);
                    } else {
                        $('#zorlinq32-ai-form-msg').text('❌ ' + (res.data && res.data.message ? res.data.message : '저장 실패')).css('color','#d32f2f');
                        $('#zorlinq32-ai-save-tpl-btn').prop('disabled', false).text('💾 템플릿 저장');
                    }
                }).fail(function() {
                    $('#zorlinq32-ai-form-msg').text('❌ 서버 오류').css('color','#d32f2f');
                    $('#zorlinq32-ai-save-tpl-btn').prop('disabled', false).text('💾 템플릿 저장');
                });
            });

            /* ── 초기화 ─────────────────────────────── */
            $('#zorlinq32-ai-reset-form-btn').on('click', function() {
                $('#zorlinq32-ai-tpl-name, #zorlinq32-ai-attachment-id, #zorlinq32-ai-edit-idx').val('');
                $('#zorlinq32-ai-upload-name').text('선택된 파일 없음');
                $('#zorlinq32-ai-preview-img').hide().attr('src', '');
                $('#zorlinq32-ai-title-rect, #zorlinq32-ai-sub-rect').hide();
                $('#f-title-x').val('0.05'); $('#f-title-y').val('0.55');
                $('#f-title-w').val('0.90'); $('#f-title-h').val('0.25');
                $('#f-sub-x').val('0.05');   $('#f-sub-y').val('0.80');
                $('#f-sub-w').val('0.90');   $('#f-sub-h').val('0.12');
                $('#zorlinq32-ai-form-msg').text('');
                mediaFrame = null;
            });

            /* ── 삭제 ──────────────────────────────── */
            $(document).on('click', '.zorlinq32-ai-del-btn', function() {
                if (!confirm('이 템플릿을 삭제하시겠습니까?')) return;
                var idx = $(this).data('idx');
                var $card = $('#zorlinq32-ai-card-' + idx);
                $.post(ajaxUrl, { action: 'zorlinq32_ai_delete_template', nonce: nonce, idx: idx }, function(res) {
                    if (res.success) {
                        $card.fadeOut(300, function() { $(this).remove(); });
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '삭제 실패');
                    }
                });
            });

            /* ── 수정 ──────────────────────────────── */
            $(document).on('click', '.zorlinq32-ai-edit-btn', function() {
                var idx = parseInt($(this).data('idx'), 10);
                var t   = tplData[idx];
                if (!t) return;
                $('#zorlinq32-ai-edit-idx').val(idx);
                $('#zorlinq32-ai-tpl-name').val(t.name);
                $('#zorlinq32-ai-attachment-id').val(t.attachment_id);
                $('#zorlinq32-ai-upload-name').text('기존 이미지 유지 (새로 선택하려면 업로드 버튼 클릭)');
                $('#f-title-x').val(t.title_x); $('#f-title-y').val(t.title_y);
                $('#f-title-w').val(t.title_w); $('#f-title-h').val(t.title_h);
                $('#f-sub-x').val(t.sub_x);     $('#f-sub-y').val(t.sub_y);
                $('#f-sub-w').val(t.sub_w);     $('#f-sub-h').val(t.sub_h);
                loadPreview(t.preview_url);
                $('html,body').animate({ scrollTop: 0 }, 400);
            });
        });
        </script>
        <?php
    }

    /* ── 콘텐츠 생성 ── */
    private function generate_blog_content( $topic, $type ) {
        $prompt = $this->build_prompt( $topic, $type );
        return $this->generate_with_gemini( $prompt, $topic, $type );
    }

    private function generate_with_gemini( $prompt, $topic = '', $type = 'informational' ) {
        $allowed = $this->get_allowed_html();

        // ✅ cloud-press(Cloudflare Worker/Pages) 검색 그라운딩 API 호출 결과를
        // 프롬프트 앞부분에 삽입 (Gemini 내장 google_search 툴이 무료 티어에서
        // 429로 막히는 문제 우회)
        // ⚠️ 수정: topic이 비어 있을 때 $prompt(수천 자에 달하는 전체 지시문)를
        // 그대로 검색어로 보내면 상대 검색 사이트로의 요청이 과도하게 커져
        // HTTP 413(Request Entity Too Large)을 유발할 수 있었다. topic이 없을
        // 때는 $prompt 앞부분 일부(최대 60자)만 검색어로 사용하도록 안전하게 자른다.
        $search_query_for_grounding = $topic !== '' ? $topic : mb_substr( $prompt, 0, 60, 'UTF-8' );
        $grounding_block = $this->fetch_search_grounding( $search_query_for_grounding, 5 );
        $prompt_with_grounding = ! empty( $grounding_block ) ? ( $grounding_block . $prompt ) : $prompt;

        $body    = [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt_with_grounding ] ] ] ],
            // ⚠️ 2026-08 수정(글 잘림 버그): 이 플러그인의 프롬프트는 FAQ 포함 H2
            // 4개 이상 + H2마다 H3 2~3개 + 각 H3마다 문단·리스트를 요구하는 매우
            // 긴 구조라, 8000 토큰으로는 실제로 자주 부족해 MAX_TOKENS로 중간에
            // 잘리는 일이 잦았다(원인 불명 "가끔씩만 정상 생성" 버그의 핵심 원인).
            // 여유 있게 12000으로 올려 애초에 잘릴 확률 자체를 낮춘다.
            'generationConfig' => [ 'temperature' => 0.3, 'topK' => 40, 'topP' => 0.90, 'maxOutputTokens' => 12000 ],
        ];
        $data = $this->call_gemini_api( $body, 160, 'gemini-3.5-flash' );
        if ( is_wp_error( $data ) ) return $data;

        // ⚠️ 2026-08 추가: finishReason이 MAX_TOKENS인지 먼저 확인한다.
        // extract_text()가 텍스트를 정상적으로 돌려주더라도(빈 응답은 아니지만
        // 문장/태그 중간에서 뚝 끊긴 상태) 이 정보를 놓치면 잘린 본문이 그대로
        // 저장되어 버린다. 아래에서 이 값을 이어쓰기 루프의 트리거로 사용한다.
        $was_truncated_by_api = ( 'MAX_TOKENS' === $this->get_finish_reason( $data ) );

        $text = $this->extract_text( $data );
        if ( is_wp_error( $text ) ) return $text;

        $meta_info = $this->extract_meta_info( $text );
        // 제목은 사용자가 직접 작성 — title 필드 제거
        unset( $meta_info['title'] );
        $text      = preg_replace( '/<!--\s*(TITLE|META_DESC|SLUG|FOCUS_KEYWORD):[\s\S]*?-->\s*/i', '', $text );
        $text      = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>\s*/i', '', $text );
        $text      = preg_replace( '/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $text );
        $text      = preg_replace( '/\*(.+?)\*/us', '$1', $text );
        $text      = str_replace( '*', '', $text );
        // ── H4 태그 완전 제거: h4 → h3 상향 변환 ──
        $text      = preg_replace( '/<h4([^>]*)>/i', '<h3$1>', $text );
        $text      = preg_replace( '/<\/h4>/i', '</h3>', $text );
        $html      = wp_kses( trim( $text ), $allowed );

        /* ── 이어쓰기 ──
           ⚠️ 2026-08 수정: 기존에는 "공백 제외 글자수가 1200자 미달일 때만"
           이어쓰기가 발동해, 예를 들어 3000자를 쓰다가 MAX_TOKENS로 뚝 잘려도
           1200자를 이미 넘겼다는 이유로 잘린 채 그대로 마무리되는 것이
           "가끔씩만 정상, 대부분 잘림" 버그의 직접 원인이었다. 이제는
           (a) 글자수 미달이거나 (b) API가 MAX_TOKENS로 잘랐거나
           (c) HTML이 닫히지 않은 태그로 끝나 잘린 것으로 보이는 경우
           중 하나라도 해당하면 이어쓰기를 시도한다. 최대 시도 횟수도 늘려
           (2 → 3) 심하게 잘린 경우에도 완성될 여지를 준다. */
        $target_chars = 1200;
        // 공백 제외 글자수 계산: HTML 태그 제거 → HTML 엔티티 디코딩 → 모든 공백(유니코드 포함) 제거 → 글자수
        $plain_text   = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $char_count   = mb_strlen( preg_replace( '/[\s\x{00A0}\x{3000}]+/u', '', $plain_text ), 'UTF-8' );

        $needs_continue = ( $char_count < $target_chars ) || $was_truncated_by_api || $this->looks_truncated( $html );

        for ( $i = 0; $i < 3 && $needs_continue; $i++ ) {
            // 남은 예산이 부족하면(이어쓰기 왕복에 최소 필요한 시간도 없으면) 지금까지의 결과로 마무리하되,
            // 최소한 미완성 태그/문장만이라도 다듬어 사용자에게 보이는 결과가 어색하지 않게 한다.
            if ( $this->time_left() < 8 ) {
                $html = $this->close_dangling_html( $html );
                break;
            }

            $was_hard_truncated = $was_truncated_by_api || ( $char_count >= $target_chars && $this->looks_truncated( $html ) );

            $tail    = mb_substr( wp_strip_all_tags( $html ), -500, null, 'UTF-8' );
            $remain  = max( 0, $target_chars - $char_count );
            $tbl_note = in_array( $type, [ 'policy_guide', 'utility' ], true )
                ? '- <table>: 이 유형(policy_guide/utility)에서만 허용 — H3 직후 1~2개만 사용'
                : '- <table>: 이 유형에서 절대 사용 금지 — 비교·요약은 반드시 <ul>/<ol>로만';
            $img_note  = '- <img>: src는 반드시 실제 접근 가능한 URL만 사용 (가짜·임의 URL 절대 금지). 불확실하면 HTML 주석으로 대체';

            // ⚠️ 잘려서 재요청하는 경우와, 단순히 분량이 부족해 새 섹션을
            // 추가하는 경우는 지시가 달라야 한다. 잘린 경우 "새 섹션을 시작"
            // 하라고 하면 미완성 문장/태그가 그대로 방치된 채 새 내용이
            // 이어 붙어 어색한 결과가 나온다. 반드시 "끊긴 지점부터 자연스럽게
            // 이어서" 작성하도록 명시한다.
            $continuation_instruction = $was_hard_truncated
                ? "⚠️ 아래 '이전 글 끝부분'은 응답 길이 제한으로 문장 또는 태그가 완성되지 못한 채 중간에서 끊겼습니다.
- 끊긴 부분부터 자연스럽게 이어서 문장을 완성하세요 (끊긴 단어를 앞에서부터 다시 쓰지 말 것).
- 만약 끊긴 지점이 <li> 항목 도중이라면 그 <li>를 먼저 완성한 뒤 </ul> 또는 </ol>로 목록을 정상적으로 닫으세요.
- 만약 끊긴 지점이 <p> 문단 도중이라면 그 문장을 완성한 뒤 </p>로 닫으세요.
- 그 다음 이어서 목표 분량({$target_chars}자)까지 남은 섹션(H2/H3/FAQ 등)을 정상적으로 계속 작성하세요."
                : "블로그 글이 공백제외 {$char_count}자입니다. 목표 {$target_chars}자까지 {$remain}자 이상 추가 작성하세요.";

            $cp      = "{$continuation_instruction}

━━ 반드시 준수 (위반 즉시 재작성) ━━
- 허용 태그: h2/h3/p/ul/ol/li/strong/u/img (별표·한자·마크다운·SEO주석 금지)
- H1·H4 태그 절대 금지 / <title> 태그 절대 금지
{$tbl_note}
{$img_note}

━━ 구조 규칙 (새로 추가하는 섹션에도 동일 적용) ━━
- H2 섹션 1~2개 추가 (FAQ 포함 총 H2 최소 4개 유지)
- 각 H2 직후: 요약 <p> 1개 (1~2문장)
- 각 H2 안에 H3 반드시 2~3개
- 각 H3 직후: <p> 1~3문장 → 그 다음 <ul>/<ol> (li 최소 3개)
- H3 섹션에서 H4 완전 금지 — H3 다음은 반드시 <p>+<ul>/<ol> 구조
- strong: 핵심 수치·키워드에 섹션당 2~4개 (단어·구문 단위만)
- u: 중요 용어·핵심 키워드에 섹션당 1~2개
- 문장은 자연스럽게 (20~70자) / 비자연스러운 어구 절대 금지
- 새로운 관점·심화 정보만 추가 (기존 내용 반복 금지)

이전 글 끝부분:
{$tail}

이어서 (HTML만 출력, 위에서 지시한 대로 끊긴 부분부터 자연스럽게):";
            // 이어쓰기는 본문 생성 시 이미 조회한 검색 그라운딩 결과를 재사용 (같은 주제이므로 재검색 불필요)
            $cp_with_grounding = ! empty( $grounding_block ) ? ( $grounding_block . $cp ) : $cp;
            // 직전 호출(본문 생성 또는 이전 이어쓰기) 바로 뒤에 연달아 요청하면
            // 무료 티어의 분당 요청 한도(RPM)에 걸리기 쉬우므로 짧게 텀을 둔다(남은 예산 안에서만).
            if ( $this->time_left() > 5 ) sleep( 2 );
            $cd = $this->call_gemini_api(
                [ 'contents' => [ [ 'parts' => [ [ 'text' => $cp_with_grounding ] ] ] ], 'generationConfig' => [ 'temperature' => 0.3, 'maxOutputTokens' => 4000 ] ],
                120,
                'gemini-3.5-flash'
            );
            if ( is_wp_error( $cd ) ) {
                // 이어쓰기 호출 자체가 실패하면 지금까지의 본문이라도 태그를 안전하게 닫고 종료.
                $html = $this->close_dangling_html( $html );
                break;
            }

            $continuation_truncated = ( 'MAX_TOKENS' === $this->get_finish_reason( $cd ) );

            $ct = $this->extract_text( $cd );
            if ( is_wp_error( $ct ) ) {
                $html = $this->close_dangling_html( $html );
                break;
            }
            $ct   = preg_replace( '/<!--[\s\S]*?-->/i', '', $ct );
            $ct   = preg_replace( '/```[\s\S]*?```/i', '', $ct );
            $ct   = preg_replace( '/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $ct );
            $ct   = preg_replace( '/\*(.+?)\*/us', '$1', $ct );
            $ct   = str_replace( '*', '', $ct );
            // ── H4 태그 완전 제거 (이어쓰기 결과에도 동일 적용) ──
            $ct   = preg_replace( '/<h4([^>]*)>/i', '<h3$1>', $ct );
            $ct   = preg_replace( '/<\/h4>/i', '</h3>', $ct );

            // 잘렸던 지점을 이어서 완성한 경우, 원본 $html의 마지막(미완성) 조각과
            // 새로 받은 $ct의 시작 부분을 그대로 이어 붙이면 된다(둘 다 같은
            // 문장/태그의 앞/뒤 절반이므로 사이에 개행을 넣지 않는다). 반면
            // 새 섹션을 추가하는 일반적인 경우는 기존처럼 개행으로 구분한다.
            $joined = $was_hard_truncated ? ( $html . trim( $ct ) ) : ( $html . "\n" . trim( $ct ) );
            $html   = wp_kses( $joined, $allowed );

            // 이어쓰기 후 공백 제외 글자수 재계산
            $plain_text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $char_count = mb_strlen( preg_replace( '/[\s\x{00A0}\x{3000}]+/u', '', $plain_text ), 'UTF-8' );

            $was_truncated_by_api = $continuation_truncated;
            $needs_continue       = ( $char_count < $target_chars ) || $continuation_truncated || $this->looks_truncated( $html );
        }

        // ── 루프가 끝난 뒤에도 여전히 잘린 것처럼 보이면(예: 3회 시도를 모두
        // 소진했는데도 마지막 조각이 미완성인 경우), 사용자에게 어색한 미완성
        // 문장/깨진 태그가 그대로 노출되지 않도록 안전하게 마무리한다. ──
        if ( $this->looks_truncated( $html ) ) {
            $html = $this->close_dangling_html( $html );
        }

        // ── 최종 H4 잔존 태그 제거 (프롬프트 무시로 생성된 경우 방어 처리) ──
        $html = preg_replace( '/<h4([^>]*)>/i', '<h3$1>', $html );
        $html = preg_replace( '/<\/h4>/i', '</h3>', $html );

        return [ 'html' => $html, 'meta_info' => $meta_info ];
    }

    /**
     * 이어쓰기를 모두 소진했는데도 여전히 미완성 태그/문장으로 끝나는 경우의
     * 최종 안전망. 완전한 문장 파서는 아니지만, 흔한 패턴(여는 태그만 있고
     * 닫는 태그가 없는 경우, 마지막 <li>가 </ul>/</ol> 없이 끝난 경우)을
     * 감지해 태그를 안전하게 닫아, 최소한 마크업이 깨지지 않은 상태로
     * 사용자에게 전달되도록 한다.
     */
    private function close_dangling_html( $html ) {
        $html = rtrim( trim( (string) $html ) );
        if ( '' === $html ) return $html;

        // 마지막으로 열린 블록 태그가 무엇인지 스택으로 추적해, 닫히지 않은
        // 태그들을 역순으로 닫아준다.
        $block_tags = [ 'ul', 'ol', 'li', 'p', 'h2', 'h3', 'table', 'tbody', 'figure', 'div' ];
        $stack = [];
        if ( preg_match_all( '/<(\/?)(' . implode( '|', $block_tags ) . ')\b[^>]*>/i', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $is_closing = ( '/' === $m[1] );
                $tag        = strtolower( $m[2] );
                if ( $is_closing ) {
                    // 스택 top이 같은 태그면 pop, 아니면(불일치) 그냥 무시(방어적으로 처리)
                    if ( ! empty( $stack ) && end( $stack ) === $tag ) array_pop( $stack );
                } else {
                    $stack[] = $tag;
                }
            }
        }

        // 태그 자체가 아예 여는 채로 잘린 경우(예: '...<p' 또는 '...<img src="x' 처럼
        // '>' 없이 끝난 경우)는 그 조각 자체를 제거한다 — 어설프게 닫아봐야 깨진 HTML만 남는다.
        if ( preg_match( '/<[a-z][^>]*$/i', $html ) ) {
            $html = preg_replace( '/<[a-z][^>]*$/i', '', $html );
        }

        // 마지막 텍스트가 문장부호 없이 뚝 끊긴 경우(문장 중간)도 흔하다.
        // 완벽하게 판별할 수는 없으므로, 최소한 열린 태그만 안전하게 닫는다.
        while ( ! empty( $stack ) ) {
            $html .= '</' . array_pop( $stack ) . '>';
        }

        return $html;
    }

    private function extract_text( $data ) {
        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) return $data['candidates'][0]['content']['parts'][0]['text'];
        if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
            $parts = [];
            foreach ( $data['candidates'][0]['content']['parts'] as $part ) { if ( isset( $part['text'] ) ) $parts[] = $part['text']; }
            if ( ! empty( $parts ) ) return implode( "\n\n", $parts );
        }

        // ⚠️ 2026-08 추가: MAX_TOKENS로 끊긴 응답은 parts 자체가 비어 있거나
        // (content가 thought summary만 있는 경우 등) 위 두 케이스에 안 걸릴 수
        // 있다. 이 경우 "텍스트가 없습니다"라는 오해의 소지가 있는 일반 오류
        // 대신, 원인을 정확히 알려주는 오류를 반환해 상위 호출부가 재시도/
        // 이어쓰기 전략을 취할 수 있게 한다.
        $finish_reason = $data['candidates'][0]['finishReason'] ?? '';
        if ( 'MAX_TOKENS' === $finish_reason ) {
            return new WP_Error( 'max_tokens_truncated', 'API 응답이 maxOutputTokens 한도에 도달해 잘렸습니다(본문 텍스트 없음).' );
        }
        if ( 'SAFETY' === $finish_reason || 'RECITATION' === $finish_reason ) {
            return new WP_Error( 'blocked_response', 'API가 안전 필터 또는 저작권 필터로 응답을 차단했습니다(finishReason: ' . $finish_reason . ').' );
        }

        return new WP_Error( 'empty_response', 'API 응답에 텍스트가 없습니다.' );
    }

    /**
     * Gemini 응답의 finishReason을 조회한다.
     * 'MAX_TOKENS'면 maxOutputTokens 한도 때문에 문장/태그 중간에서 강제로
     * 잘렸다는 뜻이다 — 이 경우 extract_text()가 텍스트를 정상적으로 돌려줘도
     * (parts[0].text 자체는 있지만 마지막 문장이 미완성인 상태) 잘린 사실을
     * 알아야 이어쓰기를 강제로 트리거할 수 있다.
     */
    private function get_finish_reason( $data ) {
        return isset( $data['candidates'][0]['finishReason'] ) ? $data['candidates'][0]['finishReason'] : '';
    }

    /**
     * HTML이 "끊긴 채로 끝났는지" 휴리스틱으로 판정한다.
     * MAX_TOKENS로 잘리면 여는 태그만 있고 닫는 태그가 없거나(예: <p>텍스트 중간에 뚝),
     * 마지막 블록 태그가 정상적으로 닫히지 않은 채 응답이 종료되는 패턴이 흔하다.
     * 완벽한 HTML 파서는 아니지만, "본문이 갑자기 잘려나갔다"를 감지하는
     * 용도로는 충분히 신뢰할 수 있다.
     */
    private function looks_truncated( $html ) {
        $trimmed = rtrim( trim( (string) $html ) );
        if ( '' === $trimmed ) return true;

        // 정상적으로 끝나야 할 마지막 블록 태그들(</p>, </li>, </ul>, </ol>, </h2>, </h3> 등)
        // 중 하나로 끝나지 않으면 중간에 잘렸을 가능성이 매우 높다.
        if ( preg_match( '/<\/(p|li|ul|ol|h2|h3|table|tbody|figure|div)>\s*$/i', $trimmed ) ) {
            return false;
        }

        // 여는 태그로 끝나거나(예: "<p" 잘림), 태그 없이 일반 텍스트 중간에서
        // 끝난 경우도 잘림으로 간주한다.
        return true;
    }

    private function get_allowed_html() {
        return [
            'h2' => [ 'class' => [], 'id' => [] ], 'h3' => [ 'class' => [], 'id' => [] ],
            'p'  => [ 'class' => [], 'style' => [] ], 'ul' => [ 'class' => [] ], 'ol' => [ 'class' => [] ], 'li' => [ 'class' => [] ],
            'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [],
            'a' => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [] ],
            'img' => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [], 'class' => [], 'style' => [], 'loading' => [] ],
            'figure' => [ 'class' => [] ], 'figcaption' => [ 'class' => [] ],
            'table' => [ 'class' => [], 'border' => [], 'cellpadding' => [], 'cellspacing' => [] ],
            'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [ 'colspan' => [], 'rowspan' => [] ], 'td' => [ 'colspan' => [], 'rowspan' => [] ],
            'div' => [ 'class' => [], 'style' => [] ], 'span' => [ 'class' => [], 'style' => [] ],
        ];
    }

    private function get_allowed_html_no_table() {
        return [
            'h2' => [ 'class' => [], 'id' => [] ], 'h3' => [ 'class' => [], 'id' => [] ],
            'p'  => [ 'class' => [], 'style' => [] ], 'ul' => [ 'class' => [] ], 'ol' => [ 'class' => [] ], 'li' => [ 'class' => [] ],
            'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [],
            'a' => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [] ],
            'img' => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [], 'class' => [], 'style' => [], 'loading' => [] ],
            'figure' => [ 'class' => [] ], 'figcaption' => [ 'class' => [] ],
            'div' => [ 'class' => [], 'style' => [] ], 'span' => [ 'class' => [], 'style' => [] ],
        ];
    }

    /* ── 프롬프트 빌드 ── */
    private function build_prompt( $topic, $type ) {
        $current_date = date( 'Y년 m월 d일' );
        $year         = date( 'Y' );

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           ① 유사문서 완전 차단 — 매 생성마다 고유 시드
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        $ts       = time();
        $th       = crc32( $topic );
        $micro    = (int)( microtime( true ) * 1000 ) % 997;
        $rand_ext = wp_rand( 0, 9999 ); // ✅ v3.7.0: WordPress 보안 난수 추가
        $seed_idx = abs( $ts + $th + $micro + $rand_ext ) % 20;

        $intros = [
            '도입부A: 독자가 지금 막 겪는 구체적 상황을 2문장으로 묘사하고 즉시 핵심 정보로 전환',
            '도입부B: 가장 흔한 실수 2가지를 먼저 짚고 올바른 방법으로 자연 전환',
            '도입부C: 핵심 수치(금액/기간/비율)를 첫 문장에 배치 — 역피라미드 결론 먼저',
            '도입부D: 이 글에서 다룰 3가지 핵심 포인트를 첫 단락에 명시하는 약속형',
            '도입부E: 실무 경험자 관점의 1인칭 사례 소개 → E-E-A-T 신뢰 즉시 구축',
            '도입부F: 독자가 검색창에 치는 질문 그 자체로 시작 → 즉시 답변 제공',
            '도입부G: 최신 변화·정책 변경 강조 → 신선도 어필로 클릭 유지',
            '도입부H: 비용 절감·시간 단축·리스크 회피 3가지 실익을 수치와 함께 배치',
            '도입부I: 흔한 오해를 먼저 지적하고 실제와 대비하는 교정 구조',
            '도입부J: 자가진단 체크리스트 3항목으로 시작 → 해당되면 이 글이 필요하다는 흐름',
            '도입부K: 최근 통계·연구 수치로 시작 → 신뢰도와 검색 의도 동시 공략',
            '도입부L: 성공 사례(구체적 숫자)와 실패 사례를 대조하는 스토리텔링',
            '도입부M: 비교 대상 2~3가지를 첫 문단에 나열 → 선택 의도 직격',
            '도입부N: 독자가 얻는 구체적 이득을 약속 형식으로 명시',
            '도입부O: 시간순 흐름 (예전에는~, 지금은~) → 변화 맥락으로 필요성 설득',
            '도입부P: 한 줄 요약(TL;DR) 먼저 제시 후 심화 전개',
            '도입부Q: 독자가 실제로 궁금해하는 생활 밀착형 질문으로 시작',
            '도입부R: 주변 사람 사례를 들어 공감 확보 후 해결책 제시',
            '도입부S: 관련 제도·정책의 핵심 변경 사항을 첫 줄에 배치',
            '도입부T: 이 주제를 모르면 손해 보는 이유를 3줄로 압축해 위기감 조성',
        ];
        $struct_seed = $intros[ $seed_idx ];

        $h2_styles = [
            '소제목 형식: 질문형 ("왜 ~인가?", "어떻게 ~하나?", "~이 중요한 이유?")',
            '소제목 형식: 숫자 포함형 ("3가지 핵심", "5단계 가이드", "7가지 주의사항")',
            '소제목 형식: 결과 중심형 ("~하면 달라지는 것", "~의 실제 효과", "~로 해결")',
            '소제목 형식: 직접 키워드형 ("~의 모든 것", "~를 위한 핵심", "~완벽 정리")',
        ];
        $h2_style = $h2_styles[ abs( $th + $micro ) % 4 ];

        $tone_styles = [
            '문장: 단문 위주(20~35자), 명확 빠른 호흡, 정보 전달 최우선',
            '문장: 중문 위주(35~60자), 이유·근거를 같은 문장에 포함',
            '문장: 단문·중문 교차, 강조는 단문·설명은 중문, 리듬감',
            '문장: 구어체 혼합(~입니다/~합니다+~이에요/~거든요), 친근·신뢰감',
        ];
        $tone_style  = $tone_styles[ abs( $th * 3 + $micro ) % 4 ];
        $unique_id   = "{$ts}-{$th}-{$micro}-{$rand_ext}";

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           ② 글 유형별 고유 특성
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

        /* ── 정보성 ── */
        $g_info = "
【정보성 — 2026 Elite SEO Content Specialist 기준 + 네이버 웹문서 1위 장악 + 애드센스 수익화 극대화】

# Role: 2026 Elite SEO Content Specialist
당신은 실전 경험이 풍부한 SEO·콘텐츠 제작 전문가입니다. 2026년 기준 구글 최신 검색 알고리즘, E-E-A-T 원칙, 네이버 C-Rank·다이아 로직을 완벽하게 이해하고 있습니다.

━━ 제목 전략 (네이버 웹문서 섹션 1위 장악 필수) ━━
- '{$topic}'와 연관된 3~5개 키워드 변형 분석 → 네이버 웹문서 순위 가장 높은 것을 메인 키워드로 선정
- 서브 키워드: 메인 키워드 포함 + 검색량 높은 연관어 추가
- 연관 키워드 최소 30개를 본문 전체에 자연 분산 배치
- '|' 절대 금지 / 연도 삽입 절대 금지

━━ 1️⃣ 메타 디스크립션 ━━
- 길이: 공백 포함 120~160자 내외
- 메인 키워드 + 서브 키워드(1개 이상) 필수 포함
- \"~에 대해 알아봅니다\" 금지 → \"2026년 기준 ~하는 방법과 주의사항을 알려드립니다\" 형태

━━ 2️⃣ 도입부 ━━
- 인사말·불필요한 서론 절대 금지
- 첫 문장: 메인 키워드 + 서브 키워드 1~2개 자연 포함
- 도입부 <p> 1개 (2~3문장 — 독자 상황 공감 → 핵심 정보 약속)

━━ ★ 3️⃣ 본문 구조 (H2/H3만 사용 — H4 완전 금지) ★ ━━
[본론 H2] FAQ 제외 반드시 3개 이상 (FAQ H2 포함 총 H2 최소 4개)
- 각 H2 직후: 섹션 요약 <p> 1개 (1~2문장)
- 각 H2 내부에 H3 반드시 2~3개

[각 H3 구조 — 핵심 규칙]
① H3 태그 직후: <p> 1~3문장 (핵심 설명, 수치·근거 포함)
② 그 다음: 반드시 <ul> 불릿포인트 (li 최소 3개)
③ H3 내부에 H4 절대 금지 — H3 → <p> → <ul> 구조가 전부

⚠️ H4 태그 완전 금지: 이 글 어디에도 h4 태그 사용 절대 불가

━━ 4️⃣ strong 태그 & 밑줄(<u>) ━━
- <strong>: 핵심 수치·키워드·결론에 섹션당 2~4개 (단어·구문 단위 — 문장 전체 strong 금지)
- <u>: 중요 용어·브랜드명·핵심 개념에 섹션당 1~2개
- 남용 금지 (전체의 10~15% 이내) — 너무 많으면 강조 효과 사라짐

━━ 5️⃣ FAQ (★ 필수 4~6개) ━━
- 위치: 마지막 본론 H2 직후
- <h2>자주 묻는 질문</h2>
- 독자가 실제로 검색하는 질문 4~6개 선정
- 각 Q: <h3>질문?</h3> / 각 A: <p>2~4문장 — 명확한 수치·조건 포함 답변</p>

━━ 6️⃣ 끝인사 절대 금지 ━━
- \"지금까지~\", \"도움이 되셨기를\", \"결론적으로\" 완전 금지
- FAQ 직후 마지막 정리 문단 <p> 1개 후 깔끔하게 종료

━━ 표(table) — 정보성 글에서 절대 사용 금지 ━━
- <table> 완전 금지 / 비교·요약은 반드시 <ul>/<ol> 리스트로만

━━ 이미지(img) 삽입 규칙 ━━
- 본론 H2 섹션 사이 2~3개 삽입
- 불확실한 URL 금지 → HTML 주석: <!-- [이미지 위치] alt: [{$topic} 관련 이미지] 800x400 -->
- alt 텍스트: 메인 키워드 포함 / loading=\"lazy\" 필수

━━ 네이버 SEO 특화 ━━
- C-Rank: 전문성(수치·출처) + 활동성(최신 날짜) + 신뢰성(경험 서술)
- 모바일 가독성: 문장 1개 최대 2줄 이내 / 단락 3~4줄 이내

━━ 애드센스 수익화 극대화 ━━
- 고단가 키워드(금융·보험·건강·법률·부동산) 자연 배치 — 본문 3~5회
- 각 H2 섹션이 단일 주제에 집중 → 애드센스 문맥 매칭 정확도 상승
- H3 불릿포인트에 관련 서비스·제품 키워드 자연 삽입
- 독자 체류 극대화: 각 섹션이 다음 섹션으로 자연 유도 (스크롤 방문 광고 노출 증가)";

        /* ── 유틸리티 ── */
        $g_util = "
【유틸리티 — 2026 Elite SEO Content Specialist + 표 2개 필수 + 네이버 웹문서 1위 + 애드센스 최적화】

# Role: 2026 Elite SEO Content Specialist
독자가 이 글 한 편으로 다운로드·설치·발급·신청을 완전히 끝낼 수 있어야 합니다.

━━ 제목 전략 ━━
- '{$topic}'와 연관된 3~5개 키워드 변형 → 네이버 웹문서 순위 가장 높은 것을 메인 키워드로
- 핵심 동작 동사 필수: 다운로드·설치·신청·발급·방법 ('|' 절대 금지, 연도 삽입 절대 금지)
- 연관 키워드 30개 이상 본문 전체 자연 분산

━━ 1️⃣ 메타 디스크립션 ━━
- 120~160자 / 메인 키워드 + 서브 키워드 포함 / 문제 해결 약속형

━━ 2️⃣ 도입부 ━━
- 인사말·불필요한 서론 절대 금지
- 도입부 <p> 1개 (1~2문장 — 무엇인지 간결 정의 + 이 글로 해결할 내용)

━━ ★ 3️⃣ 표 2개 필수 (도입부 직후, 본론 H2 전 배치) ★ ━━
⚠️ 두 표는 도입부 <p> 바로 다음, 본론 H2 시작 전에 반드시 배치

★ [표1 기본 정보] — 카테고리·운영체제·개발사·공식사이트·버전·비용·라이선스
★ [표2 사양·조건] — CPU·메모리·저장공간·운영체제 또는 자격조건·필요서류·신청기간·비용
(두 표 모두 thead+tbody 구조 / <th> 필수)

━━ ★ 4️⃣ 본문 구조 (H2/H3만 — H4 완전 금지) ★ ━━
[본론 H2] FAQ 제외 3개 이상 (FAQ 포함 총 H2 최소 4개)
- 각 H2 직후: 요약 <p> 1개 (1~2문장)
- 각 H2 내부에 H3 반드시 2~3개

[각 H3 구조 — 핵심 규칙]
① H3 직후: <p> 1~3문장 (설명·이유 포함)
② 그 다음: <ul> 또는 <ol> (li 최소 3개)
③ H3 내부 H4 절대 금지

⚠️ H4 태그 완전 금지

━━ 5️⃣ strong 태그 & 밑줄(<u>) ━━
- <strong>: 핵심 수치·단계·주의사항에 섹션당 2~4개
- <u>: 중요 용어·브랜드명에 섹션당 1~2개 / 남용 금지

━━ 6️⃣ FAQ (필수 4~6개) ━━
- 위치: 마지막 본론 H2 직후
- <h2>자주 묻는 질문</h2>
- 4~6개 Q&A / 각 Q: <h3>질문?</h3> / 각 A: <p>2~4문장 구체적 해결책</p>

━━ 끝인사 절대 금지 ━━

━━ 쉬운 설명 필수 ━━
- 전문 용어 사용 즉시 괄호로 쉬운 말 병기
- 설치·신청 단계는 <ol> 사용 (H3 직후 순서 있는 단계 전용)

━━ 이미지 삽입 ━━
- 표2 직후 + 본론 중간 총 2개 / 불확실 URL → HTML 주석 대체

━━ 네이버 SEO & 애드센스 최적화 ━━
- C-Rank: 전문성(정확 수치) + 활동성(최신 날짜) + 신뢰성(경험 서술)
- 각 H2 섹션이 단일 주제 집중 → 애드센스 문맥 광고 매칭 정확도 극대화
- 고단가 키워드(금융·보험·건강·법률) 자연 배치 — 본문 3~5회";

        /* ── 정책·공공 ── */
        $g_policy = "
【정책·공공 — 2026 Elite SEO Content Specialist + 표 선택사항(최대 2개) + 네이버 웹문서 1위 + 애드센스 최적화】

# Role: 2026 Elite SEO Content Specialist
정확한 공공 정보를 신뢰도 높게 전달하여 독자의 문제를 해결해 주는 글을 작성합니다.

━━ 제목 전략 ━━
- '{$topic}'와 연관된 3~5개 키워드 변형 → 네이버 웹문서 순위 가장 높은 것을 메인 키워드로
- 정책명 + 핵심 수혜 내용 구조 ('|' 절대 금지)
- 연도가 실제 관련된 정책이면 연도 포함 가능, 관련 없으면 금지
- 연관 키워드 30개 이상 본문 전체 자연 분산

━━ 1️⃣ 메타 디스크립션 ━━
- 120~160자 / 메인+서브 키워드 포함 / 문제 해결 약속형

━━ 2️⃣ 도입부 ━━
- 인사말·불필요한 서론 절대 금지
- 도입부 <p> 1개 (1~2문장 — 핵심 혜택 수치 먼저 + 이 글에서 다룰 내용)

━━ ★ 3️⃣ 표 (선택사항, 최대 2개) ★ ━━
⚠️ 표는 완전히 선택사항 — 아래 기준에 해당할 때만 사용
- 대상/조건/금액/기간 정보가 표가 더 명확할 때만
- 표 사용 시: 도입부 <p> 직후, 본론 H2 전 배치
- thead+tbody 구조 / <th> 필수

━━ ★ 4️⃣ 본문 구조 (H2/H3만 — H4 완전 금지) ★ ━━
[본론 H2] FAQ 제외 3개 이상 (FAQ 포함 총 H2 최소 4개)
- 각 H2 직후: 요약 <p> 1개 (1~2문장)
- 각 H2 내부에 H3 반드시 2~3개

[각 H3 구조 — 핵심 규칙]
① H3 직후: <p> 1~3문장 (핵심 정보·수치 포함)
② 그 다음: <ul> 또는 <ol> (li 최소 3개)
③ H3 내부 H4 절대 금지

⚠️ H4 태그 완전 금지

━━ 5️⃣ strong 태그 & 밑줄(<u>) ━━
- <strong>: 지원 금액·신청 기간·자격 조건 수치에 섹션당 2~4개
- <u>: 중요 정책 용어·기관명에 섹션당 1~2개 / 남용 금지

━━ 6️⃣ FAQ (필수 4~6개) ━━
- 위치: 마지막 본론 H2 직후
- <h2>자주 묻는 질문</h2>
- 4~6개 Q&A / 각 Q: <h3>질문?</h3> / 각 A: <p>2~4문장 — 수치·조건 포함 명확한 답변</p>

━━ 끝인사 절대 금지 ━━

━━ 이미지 삽입 ━━
- 본론 중간 1~2개 / 불확실 URL → HTML 주석 대체

━━ 네이버 SEO & 애드센스 최적화 ━━
- C-Rank: 전문성(정확 수치·출처) + 활동성(최신 날짜) + 신뢰성(경험 서술)
- 각 H2 섹션이 단일 주제 집중 → 애드센스 문맥 광고 매칭 정확도 극대화
- 정부 지원 관련 고단가 금융·보험·법률 키워드 자연 배치 — 본문 3~5회
- 허위·과장 표현 금지 / 검증 가능한 수치만";

        /* ── 리뷰·비교 ── */
        $g_review = "
【리뷰·비교 — 쿠팡파트너스 최적화 + H2/H3 구조 + 애드센스 문맥 매칭 극대화】
▶ 목표: 쿠팡파트너스 클릭·구매 전환 극대화 + 애드센스 고단가 광고 문맥 매칭

━━ 제목 전략 ━━
- 비교 대상 + 선정 기준 구조 (연도 삽입 절대 금지, '|' 절대 금지)
- '추천', '비교', '순위', '후기', '가성비', '쿠팡' 키워드 포함

━━ 쿠팡파트너스 최적화 ━━
- 본문 전체에 제품명·모델명 구체적으로 기재
- 가격 정보 반드시 포함 (쿠팡 기준 명시)
- 각 H3 섹션 하단 <ul> 마지막 li에 쿠팡 안내 문구 삽입:
  예: <li>쿠팡에서 <strong>최저가</strong>와 로켓배송 여부를 확인할 수 있습니다.</li>
- '로켓배송', '쿠팡 최저가', '쿠팡 할인' 키워드 본문 3~5회 자연 배치

━━ ★ 본문 구조 (H2/H3만 — H4 완전 금지) ★ ━━
[도입부] <p> 1개 (1~2문장 — 선택 기준 + 이 글의 비교 범위)

[본론 H2] FAQ 제외 3개 이상 (FAQ 포함 총 H2 최소 4개)
- 각 H2 직후: 요약 <p> 1개 (1~2문장)
- 각 H2 내부에 H3 반드시 2~3개

[각 H3 구조 — 핵심 규칙]
① H3 직후: <p> 1~3문장 (제품 설명·특징·수치 포함)
② 그 다음: <ul> 불릿포인트 (li 최소 3개 — 장점·단점·특징·쿠팡 안내 포함)
③ H3 내부 H4 절대 금지

⚠️ H4 태그 완전 금지
⚠️ 리뷰에서 표(table) 절대 금지 — 모든 비교는 <ul>/<ol>로만

━━ strong 태그 & 밑줄(<u>) ━━
- <strong>: 가격·평점·핵심 기능 수치·최종 추천 제품명에 섹션당 2~4개
- <u>: 제품명·브랜드명에 섹션당 1~2개 / 남용 금지

━━ FAQ (필수 4~6개) ━━
- <h2>자주 묻는 질문</h2>
- 4~6개 Q&A / 각 Q: <h3>질문?</h3> / 각 A: <p>2~4문장 구체적 답변</p>

━━ 이미지 삽입 ━━
- H2 섹션 사이 2~3개 / 불확실 URL → HTML 주석 대체

━━ 애드센스 문맥 매칭 극대화 ━━
- 각 H2 섹션이 단일 제품 카테고리에 집중 → 쇼핑 광고 매칭 정확도 상승
- 쇼핑 관련 고단가 키워드 자연 배치 (광고 클릭률 30% 목표)
- 제품 스펙·수치는 일상 언어로 풀어 설명 (예: '램 16GB = 여러 앱 동시 실행 가능')
- 구매 경험 없는 독자도 이해 가능한 수준";

        $type_guides = [
            'informational'     => $g_info,
            'utility'           => $g_util,
            'policy_guide'      => $g_policy,
            'review_comparison' => $g_review,
        ];
        $guide = isset( $type_guides[ $type ] ) ? $type_guides[ $type ] : $g_info;

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           ③ FAQ 공통
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        $faq_rule = "

━━ FAQ 섹션 필수 (반드시 4~6개 — 전 유형 공통) ━━
본문 마지막 본론 H2 직후에 반드시 포함.
독자가 실제로 검색하는 질문 형태로 작성.

⚠️ FAQ H2 태그 필수: <h2> 사용 (절대 h3·h4 사용 금지)
⚠️ 각 질문은 반드시 <h3> 사용 — 4개 이상 6개 이하

구조 (반드시 이 태그 그대로 사용):
<h2>자주 묻는 질문</h2>
<h3>질문 내용? (실제 검색어 형태)</h3>
<p>2~4문장 답변. 수치·기간·조건 포함.</p>
(4~6개 반복 / itemscope·itemtype·itemprop 절대 금지)
⚠️ FAQ 답변 <p>에 <ul> 사용 금지 — 답변은 반드시 <p>만";

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           ⑤ 최종 프롬프트
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        return "당신은 2026 Elite SEO Content Specialist입니다. 구글·네이버·빙 3대 검색엔진 완벽 최적화 + 애드센스 광고 문맥 매칭 극대화 + 수익화 최적화 + 유사문서·탬플릿 0%에 특화된 한국어 SEO 블로그 전문 작가입니다. 목표는 검색엔진을 위한 기계적인 글이 아닌, '사용자의 문제를 해결해 주는 글'을 작성하여 네이버·구글 상위노출을 달성하는 것입니다.

오늘 날짜: {$current_date} / 주제: '{$topic}' / 글 유형: {$type}

⚡ 최신 정보 우선: Google Search 결과를 바탕으로 최신 수치·정책·가격을 반영하세요.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🚫 절대 준수 — 위반 즉시 재작성
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- 한자 0개 / 별표(*) 0개 / 마크다운 문법 0개
- 공백 제외 반드시 700자 이상 (미달 즉시 이어쓰기)
- 제목(TITLE)에 '|' 문자 절대 금지
- 본문 내 <h1> 태그 절대 사용 금지
- 본문 내 <h4> 태그 절대 사용 금지 — h4는 이 플러그인에서 완전 폐기됨
- 본문 내 <title> 태그 절대 삽입 금지
- 이미지 URL: 확인 불가한 URL 금지 — img 태그 생략하고 HTML 주석으로만 표기
- 탬플릿식 글쓰기 절대 금지 (매 생성마다 완전히 다른 구조·표현·흐름)
- 유사문서 생성 절대 금지

━━ [규칙 1] H2 개수 (★ 핵심) ━━
- FAQ H2 포함 총 H2 반드시 4개 이상
- 본론 H2: FAQ 제외 반드시 3개 이상
- FAQ H2 반드시 1개 (마지막 본론 H2 직후)

━━ [규칙 2] H3 배치 ━━
- 모든 본론 H2에 H3 반드시 2~3개
- FAQ 각 질문도 H3 사용

━━ [규칙 3] H3 직후 구조 (★ 핵심 — H4 완전 삭제) ━━
[H3 직후] 반드시 이 순서: ① <p> 1~3문장 → ② <ul>/<ol> (li 최소 3개)
[H2 직후] <p> 1개 (1~2문장 섹션 요약)
⚠️ H4 태그 완전 금지 — 어디에도 사용 불가

━━ [규칙 4] strong 태그 & 밑줄(<u>) ━━
- <strong>: 핵심 수치·키워드·결론에 섹션당 2~4개 (단어·구문 단위만 — 문장 전체 strong 금지)
- <u>: 중요 용어·브랜드명·핵심 개념에 섹션당 1~2개
- 남용 금지 (전체 텍스트의 10~15% 이내)

━━ [규칙 5] 표(table) 사용 제한 ━━
- utility: 반드시 2개 (도입부 직후 배치) / policy_guide: 선택사항 최대 2개
- informational·review_comparison: <table> 완전 금지

━━ [규칙 6] 비자연스러운 어구 완전 금지 ━━
- 반말 절대 금지 (전체 존댓말)
- 인공적 마무리: 이상으로~, 지금까지~, 살펴보았습니다, 알아보았습니다 등
- 과도한 형식: ~에 대해 알아보겠습니다, 결론적으로~, 요컨대~
- 구조 안내: 본문에서는~, 다음 섹션에서는~

━━ 기타 공통 규칙 ━━
- HTML 태그만 출력 (마크다운 코드블록 ``` 절대 금지)
- itemscope·itemtype·itemprop·<script> 본문 삽입 금지
- '광고·협찬·후원' 관련 언급 절대 금지

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔍 네이버 웹문서 섹션 1위 장악 전략 (★ 최우선 목표)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【핵심: 워드프레스 블로그는 네이버 '웹문서' 섹션에만 노출됨】
→ 웹문서 섹션 1~3위에 반드시 진입해야 클릭 발생

① 메인 키워드 선정: '{$topic}' 관련 3~5개 키워드 변형 중 네이버 웹문서에서 가장 상위 노출될 키워드를 메인으로 선정
② 서브 키워드: 경쟁도 낮고 검색량 높은 서브 키워드를 제목과 본문 앞부분에 적극 활용
③ 연관 키워드 최소 30개: 본문 전체에 자연 분산 배치 (검색 범위 최대 확장)
④ Rank Math 포커스 키워드: 자동 입력되는 포커스 키워드로 검색 시 반드시 1순위 노출 목표
⑤ C-Rank 3요소: 전문성(정확 수치·출처) + 활동성(최신 날짜) + 신뢰성(경험 서술)
⑥ 다이아 로직: 검색 의도 100% 부합 + 독자 체류 극대화 + 역피라미드 구조
⑦ 모바일 가독성: 문장 1개 최대 2줄 이내 / 단락 3~4줄 이내

【구글 E-E-A-T + Featured Snippet】
- 핵심 키워드를 첫 150자 안에 자연 삽입
- 첫 H2 직후 정의 문장 1개 (40~60자, Featured Snippet 타겟)
- LSI 유사어 30개+ 본문 전체 자연 분산

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
💰 애드센스 수익화 극대화 (문맥 매칭 최우선)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
① 각 H2 섹션이 단일 주제에 집중 → 애드센스 크롤러의 문맥 파악 정확도 극대화
② H3 불릿포인트에 관련 서비스·브랜드·제품 키워드 자연 삽입 → 고단가 광고 매칭
③ 고단가 키워드(금융·보험·건강·법률·부동산·쇼핑) 자연 배치 — 본문 전체 3~5회
④ 각 섹션 말미에 관련 키워드 자연 마무리 → 다음 광고 블록 매칭 품질 상승
⑤ 독자 체류 극대화: 각 섹션이 다음 섹션으로 자연 유도 (페이지뷰 × 광고 노출 증가)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 SEO 메타 정보 (첫 3줄 필수 출력)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
첫 줄  : <!-- META_DESC: [120~160자 — 메인키워드+서브키워드+문제해결약속형, \"~에 대해 알아봅니다\" 금지] -->
둘째 줄: <!-- SLUG: [핵심키워드 하이픈 연결] -->
셋째 줄: <!-- FOCUS_KEYWORD: [3~5개 쉼표 구분 — 메인키워드, 서브키워드, 롱테일키워드 포함] -->
주석 3줄 직후 첫 요소 = 반드시 <p> 태그
⚠️ TITLE 주석 출력 완전 금지 — 제목은 사용자가 직접 작성함

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔒 유사문서 완전 차단
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[고유 ID] {$unique_id} / [도입부] {$struct_seed} / [소제목] {$h2_style} / [문장] {$tone_style}

① H2 소제목: 뻔한 소제목 금지 — 검색 의도+고유 관점 조합
② 도입부: 독자 상황 직접 묘사 (인사말 없이 즉시 문제 해결형 시작)
③ 문장 리듬: 짧은 문장(20~35자)과 긴 문장(40~65자) 불규칙 혼재
④ 구어 표현: ~거든요, ~이에요, 사실 ~입니다 등 1~2개 자연 삽입
⑤ 경험 서술: '제가 직접 확인해보니', '실제로 써보면' 등 1~2회
⑥ 탬플릿 구조 완전 파괴: 예측 가능한 정보 배치 순서 반드시 깨뜨릴 것

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📌 글 유형 전용 가이드 [{$type}]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$guide}
{$faq_rule}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ 출력 전 최종 체크리스트
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ 한자 0개 / 별표 0개 / 마크다운 0개 / 공백 제외 700자 이상
✅ 탬플릿 금지 / 유사문서 금지 / TITLE 주석 출력 금지
✅ H4 태그 완전 사용 금지 — h2/h3만 사용
✅ H2(FAQ 포함 최소 4개) / 각 H2→H3(2~3개)
✅ H2 직후 <p>1개(1~2문장) / H3 직후 <p>1~3문장 → <ul>/<ol>(li 3개+)
✅ strong: 섹션당 2~4개 단어·구문 단위 / u: 섹션당 1~2개 / 남용 금지
✅ 표: utility=2개필수, policy_guide=선택(최대2개), info/review=금지
✅ FAQ: <h2>+<h3>+<p> / 반드시 4~6개 / 구체적 해결책 (적어도 4개 미만 금지)
✅ 끝인사 완전 금지 / FAQ 후 마지막 정리 문단으로 깔끔 종료
✅ 네이버: 서브키워드 제목 포함 / 연관키워드 30개+ / C-Rank 충족
✅ 구글: 첫 150자 핵심키워드 / Featured Snippet 정의 문장 / E-E-A-T
✅ 애드센스: 각 H2 단일 주제 집중 / 고단가 키워드 3~5회 / 섹션별 관련 키워드 마무리
✅ META_DESC 120~160자 / 문제해결약속형 / 단순요약 금지

지금 바로 작성하세요! (반드시 700자 이상, H4 금지, FAQ 4~6개 필수, 탬플릿 금지, 유사문서 금지)";
    }
    private function generate_seo_title( $topic, $type = 'informational' ) {
        $year = date( 'Y' );
        // 연도 허용: 정책·공공, 수익형만 (그것도 연도 관련성 있을 때)
        $year_allowed = in_array( $type, [ 'policy_guide' ], true );

        if ( $year_allowed ) {
            $pats = [
                "{$year}년 {$topic} 핵심 정보 완벽 정리",
                "{$topic} {$year}년 최신 정보 완전 분석",
                "{$year}년 {$topic} 핵심 정보와 실전 활용법",
                "{$topic} 완벽 정리 {$year}년 업데이트",
                "{$topic}에 대한 모든 것 {$year}년 최신판",
            ];
        } else {
            $pats = [
                "{$topic} 완벽 정리 꼭 알아야 할 핵심 총정리",
                "{$topic} 핵심 정보 완전 분석",
                "{$topic} 핵심 정보와 실전 활용법",
                "{$topic} 완벽 가이드 처음부터 끝까지",
                "{$topic}에 대한 모든 것 핵심만 모았습니다",
            ];
        }
        return $pats[ absint( date( 'His' ) ) % count( $pats ) ];
    }

    /* ── 메타 정보 추출 (제목 제외 — 사용자가 직접 작성) ── */
    private function extract_meta_info( $content ) {
        $info = [ 'meta_desc' => '', 'slug' => '', 'focus_keyword' => '' ];
        if ( preg_match( '/<!-- META_DESC:\s*(.+?)\s*-->/',     $content, $m ) ) $info['meta_desc']     = trim( $m[1] );
        if ( preg_match( '/<!-- SLUG:\s*(.+?)\s*-->/',          $content, $m ) ) $info['slug']          = trim( $m[1] );
        if ( preg_match( '/<!-- FOCUS_KEYWORD:\s*(.+?)\s*-->/', $content, $m ) ) $info['focus_keyword'] = trim( $m[1] );
        return $info;
    }

    /* ── SEO 주석 제거 ── */
    private function strip_seo_from_content( $html ) {
        $html = preg_replace( '/<!--\s*(TITLE|META_DESC|SLUG|FOCUS_KEYWORD):[\s\S]*?-->\s*/i', '', $html );
        $html = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>\s*/i', '', $html );
        $html = preg_replace( '/^[ \t]*(TITLE|META_DESC|SLUG|FOCUS_KEYWORD)\s*:.*$/im', '', $html );
        $html = preg_replace( '/\n{3,}/', "\n\n", $html );
        return trim( $html );
    }

    /* ── 첫 <p> 보장 ── */
    private function ensure_description_first( $html, $meta_desc = '', $topic = '' ) {
        $html = trim( $html );
        if ( empty( $html ) ) return $html;
        if ( ! preg_match( '/^\s*<(\w+)[^>]*>/i', $html, $ft ) ) return $html;
        if ( strtolower( $ft[1] ) === 'p' ) return $html;
        if ( in_array( strtolower( $ft[1] ), [ 'h1','h2','h3','h5','h6' ], true ) ) {
            if ( ! empty( $meta_desc ) ) $p = '<p>' . esc_html( $meta_desc ) . '</p>';
            elseif ( ! empty( $topic ) ) $p = '<p>' . esc_html( date('Y') . '년 ' . $topic . '에 대한 핵심 정보를 완벽하게 정리했습니다.' ) . '</p>';
            else $p = '<p>이 글에서 핵심 정보를 안내합니다.</p>';
            return $p . "\n" . $html;
        }
        return $html;
    }

    /* ── 스키마 프롬프트 ── */
    private function build_schema_prompt( $type, $title, $meta_desc, $focus_kw, $content, $post_url, $site_name ) {
        $date    = gmdate( 'c' );
        $excerpt = mb_substr( $content, 0, 3000, 'UTF-8' );
        $common  = "다음 정보로 완벽한 Google Schema.org JSON-LD를 생성하세요.\n순수 JSON만 출력. 마크다운(\`\`\`) 없이.\n\n제목: {$title}\nURL: {$post_url}\n사이트명: {$site_name}\n설명: {$meta_desc}\n키워드: {$focus_kw}\n날짜: {$date}";
        switch ( $type ) {
            case 'article':
                return "{$common}\n\n글 내용:\n{$excerpt}\n\nArticle 스키마 생성. @context, @type(Article), headline, description, datePublished, dateModified, author(@type:Person,name:'블로그 운영자'), publisher(@type:Organization,name,logo(@type:ImageObject,url:'{$post_url}/wp-content/uploads/logo.png')), mainEntityOfPage(@type:WebPage,@id:'{$post_url}'), keywords, articleSection, inLanguage('ko-KR'), wordCount 포함.";
            case 'faq':
                return "{$common}\n\n글 전체 내용:\n{$excerpt}\n\nFAQPage 스키마 생성. 글에서 질문-답변 6~8쌍 추출.\n@context, @type(FAQPage), mainEntity(Question 배열) 구조.\n각 Question: @type(Question), name(질문), acceptedAnswer(@type:Answer, text(답변)).\n질문은 실제 검색어 형태. 글에 FAQ가 없으면 본문에서 추출.";
            case 'product_review':
                return "{$common}\n\n글 내용:\n{$excerpt}\n\nProduct + AggregateRating 스키마 생성.\n@context, @type(Product), name, description, url, aggregateRating(@type:AggregateRating, ratingValue(4.0~5.0), reviewCount(10~100), bestRating:5, worstRating:1), review(@type:Review, reviewRating(@type:Rating,ratingValue,bestRating:5), author(@type:Person,name:'블로그 운영자'), reviewBody(핵심 리뷰 3문장), datePublished).";
            case 'review':
                return "{$common}\n\n글 내용:\n{$excerpt}\n\nReview 스키마 생성. 리뷰 대상(@type:영화면Movie/책이면Book/그외Thing), itemReviewed(name), reviewRating(@type:Rating,ratingValue:4.0~5.0,bestRating:5), author(@type:Person,name:'블로그 운영자'), reviewBody(핵심 요약 3문장), datePublished, publisher(@type:Organization,name:'{$site_name}').";
            case 'howto':
                return "{$common}\n\n글 내용:\n{$excerpt}\n\nHowTo 스키마 생성. @context, @type(HowTo), name, description, totalTime(ISO 8601, 예:PT30M), step(HowToStep 배열).\n각 HowToStep: @type(HowToStep), name, text, url.\n글에서 단계 3~8개 추출. 단계가 없으면 논리적 단계로 구성.";
            case 'breadcrumb':
                return "{$common}\n\nBreadcrumbList 스키마 생성. @context, @type(BreadcrumbList), itemListElement(ListItem 배열).\n각 ListItem: @type(ListItem), position(1~), name, item(URL).\n구조: 홈({$post_url}) → 카테고리(주제 기반 한글명, URL:{$post_url}/category/slug/) → 현재글(제목,URL:{$post_url}).";
            default:
                return "{$common}\n\nArticle 스키마 생성.";
        }
    }

    /* ════════════════════════════════════════════════════════
       SEO 메타태그 자동 출력 (v3.7.0 — 3대 검색엔진 상위노출 최적화)
       — title, meta description, canonical, Open Graph, Twitter Card
       — 네이버·구글·빙 크롤러 완벽 대응
    ════════════════════════════════════════════════════════ */
    public function insert_seo_meta_tags() {
        if ( ! is_singular( 'post' ) ) return;
        global $post;
        if ( ! $post ) return;

        $seo_title   = get_post_meta( $post->ID, '_ai_seo_title',     true );
        $meta_desc   = get_post_meta( $post->ID, '_ai_meta_desc',     true );
        $focus_kw    = get_post_meta( $post->ID, '_ai_focus_keyword', true );
        $slug        = get_post_meta( $post->ID, '_ai_slug',          true );

        // Rank Math / Yoast 활성 시 중복 방지
        if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) return;

        $post_url  = get_permalink( $post->ID );
        $site_name = get_bloginfo( 'name' );
        $thumb_url = get_the_post_thumbnail_url( $post->ID, 'large' ) ?: '';

        // ── 출력 ──
        echo "\n<!-- Zorlinq32 AI Writer SEO Meta -->\n";

        // 1. Title 태그
        if ( ! empty( $seo_title ) ) {
            echo '<title>' . esc_html( $seo_title ) . ' - ' . esc_html( $site_name ) . "</title>\n";
        }

        // 2. Meta Description
        if ( ! empty( $meta_desc ) ) {
            echo '<meta name="description" content="' . esc_attr( $meta_desc ) . "\">\n";
        }

        // 3. Keywords (네이버 크롤러 대응)
        if ( ! empty( $focus_kw ) ) {
            echo '<meta name="keywords" content="' . esc_attr( $focus_kw ) . "\">\n";
        }

        // 4. Canonical URL (중복 콘텐츠 방지 — 구글/빙 필수)
        echo '<link rel="canonical" href="' . esc_url( $post_url ) . "\">\n";

        // 5. Robots (색인 허용)
        echo '<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">' . "\n";

        // 6. Open Graph (페이스북·카카오·네이버 SNS 공유 최적화)
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $post_url ) . "\">\n";
        echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . "\">\n";
        if ( ! empty( $seo_title ) ) {
            echo '<meta property="og:title" content="' . esc_attr( $seo_title ) . "\">\n";
        }
        if ( ! empty( $meta_desc ) ) {
            echo '<meta property="og:description" content="' . esc_attr( $meta_desc ) . "\">\n";
        }
        if ( ! empty( $thumb_url ) ) {
            echo '<meta property="og:image" content="' . esc_url( $thumb_url ) . "\">\n";
            echo '<meta property="og:image:width" content="1200">' . "\n";
            echo '<meta property="og:image:height" content="630">' . "\n";
        }
        // Article 발행일
        $pub_date = get_the_date( 'c', $post->ID );
        $mod_date = get_the_modified_date( 'c', $post->ID );
        echo '<meta property="article:published_time" content="' . esc_attr( $pub_date ) . "\">\n";
        echo '<meta property="article:modified_time" content="' . esc_attr( $mod_date ) . "\">\n";

        // 7. Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        if ( ! empty( $seo_title ) ) {
            echo '<meta name="twitter:title" content="' . esc_attr( $seo_title ) . "\">\n";
        }
        if ( ! empty( $meta_desc ) ) {
            echo '<meta name="twitter:description" content="' . esc_attr( $meta_desc ) . "\">\n";
        }
        if ( ! empty( $thumb_url ) ) {
            echo '<meta name="twitter:image" content="' . esc_url( $thumb_url ) . "\">\n";
        }

        // 8. 네이버 블로그 최적화 메타
        echo '<meta name="naver-site-verification" content="">' . "\n"; // 네이버 서치어드바이저 인증 (값은 사용자가 직접 입력)

        echo "<!-- /Zorlinq32 AI Writer SEO Meta -->\n\n";
    }


    public function insert_schema_markup() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post ) return;

        $schemas = $this->decode_schemas( get_post_meta( $post->ID, '_ai_blog_schema_markup', true ) );
        if ( empty( $schemas ) ) return;

        // <script type="application/ld+json"> 태그를 직접 생성해서 출력
        // wp_kses를 거치지 않고 순수 JSON을 사용해 html 손상 방지
        foreach ( $schemas as $s ) {
            if ( empty( $s['json'] ) && empty( $s['html'] ) ) continue;

            if ( ! empty( $s['json'] ) ) {
                // 신규 형식: json 필드에 순수 JSON 배열/객체 저장
                $json_str = is_string( $s['json'] ) ? $s['json'] : wp_json_encode( $s['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                echo '<script type="application/ld+json">' . "
" . $json_str . "
</script>
"; // phpcs:ignore
            } else {
                // 레거시 html 필드
                $html = trim( $s['html'] );
                if ( $html && substr( $html, 0, 7 ) === '<script' ) {
                    echo $html . "
"; // phpcs:ignore
                }
            }
        }
    }



    /* ── AJAX: 템플릿 이미지 직접 업로드 (Fallback) ── */
    public function ajax_upload_template_image() {
        check_ajax_referer( 'zorlinq32_ai_template_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );

        if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => '파일 업로드 오류 (코드: ' . ( $_FILES['file']['error'] ?? -1 ) . ')' ] );
        }

        $file = $_FILES['file'];

        // 이미지 파일만 허용
        $allowed_mime = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        $finfo = new finfo( FILEINFO_MIME_TYPE );
        $mime  = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $mime, $allowed_mime ) ) {
            wp_send_json_error( [ 'message' => '이미지 파일만 업로드 가능합니다 (jpg/png/webp/gif).' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // 업로드 처리 (WordPress 표준 방식)
        $overrides = [ 'test_form' => false, 'test_type' => true ];
        $uploaded  = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            wp_send_json_error( [ 'message' => '업로드 실패: ' . $uploaded['error'] ] );
        }

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $uploaded['type'],
            'post_title'     => sanitize_file_name( pathinfo( $uploaded['file'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $uploaded['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => '미디어 등록 실패: ' . $attachment_id->get_error_message() ] );
        }

        $attach_data = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $uploaded['url'],
            'message'       => '업로드 완료',
        ] );
    }

    /* ── AJAX: 폰트 경로 저장 ── */
    public function ajax_save_font_path() {
        check_ajax_referer( 'zorlinq32_ai_template_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $path = isset( $_POST['font_path'] ) ? sanitize_text_field( $_POST['font_path'] ) : '';
        update_option( 'zorlinq32_ai_thumb_font_path', $path );
        wp_send_json_success();
    }


}
/* 부트스트랩은 zorlinq32.php의 공통 모듈 로더($module_class::instance())가 처리합니다. */
