/**
 * Zorlinq32 — AI 글쓰기 · AI 썸네일 생성 모듈 스크립트
 * (구 AIBP Pro 통합, zorlinq32 네이밍/클래스 체계로 전면 치환)
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initTabs();
        initContentGenerator();
        initSchemaGenerator();
        initContentExpander();
        initThumbnailGenerator();
        syncTopicToThumbTopic();
        populateCustomTemplateOptions();
    });

    /* ══════════════════════════════════════
       탭 초기화
    ══════════════════════════════════════ */
    function initTabs() {
        $('.zorlinq32-ai-tab').on('click', function() {
            var tab = $(this).data('tab');
            $('.zorlinq32-ai-tab').removeClass('active');
            $(this).addClass('active');
            $('.zorlinq32-ai-tab-content').removeClass('active');
            $('[data-content="' + tab + '"]').addClass('active');
        });
    }

    /* 주제 입력 → 썸네일 탭 자동 연동 */
    function syncTopicToThumbTopic() {
        $('#zorlinq32-ai-topic').on('input', function() {
            var val = $(this).val().trim();
            // (topic sync removed - template system)
        });
    }

    /* ══════════════════════════════════════
       자체 썸네일 템플릿 — select 옵션 동적 추가 및
       스타일 변경 감지 → 제목/부제목 입력란 표시/숨김
    ══════════════════════════════════════ */
    function populateCustomTemplateOptions() {
        var $select = $('#zorlinq32-ai-thumb-style');
        if ( !$select.length ) return;

        // PHP가 이미 렌더했을 수 있으므로 JS optgroup 중복 추가 방지
        if ( $select.find('optgroup[label="자체 썸네일 템플릿"]').length === 0 ) {
            var templates = (typeof zorlinq32AiWriter !== 'undefined' && zorlinq32AiWriter.templates) ? zorlinq32AiWriter.templates : [];
            if ( templates.length ) {
                var $og = $('<optgroup label="자체 썸네일 템플릿"></optgroup>');
                templates.forEach(function(t, i) {
                    $og.append('<option value="custom_tpl_' + i + '">' + escHtml(t.name || ('템플릿 ' + (i+1))) + '</option>');
                });
                $select.append($og);
            }
        }

        // 스타일 변경 감지 → 자체 템플릿이면 제목/부제목 입력란 표시
        $select.on('change', function() {
            toggleCustomTplInputs($(this).val());
        });
        toggleCustomTplInputs($select.val());
    }

    function toggleCustomTplInputs(style) {
        var $inputs = $('#zorlinq32-ai-custom-tpl-inputs');
        if ( !$inputs.length ) return;
        if ( style && style.indexOf('custom_tpl_') === 0 ) {
            $inputs.slideDown(180);
        } else {
            $inputs.slideUp(180);
        }
    }

    /* ══════════════════════════════════════
       콘텐츠 생성기
    ══════════════════════════════════════ */
    function initContentGenerator() {
        $('#zorlinq32-ai-generate-btn').on('click', function(e) {
            e.preventDefault();
            var topic = $('#zorlinq32-ai-topic').val().trim();
            if (!topic) { showResult('주제 키워드를 입력해주세요.', 'error'); return; }

            // 썸네일 탭 주제 자동 동기화
            // (topic sync removed - template system)

            var type = $('#zorlinq32-ai-type').val();
            generateContent(topic, type);
        });
    }

    function generateContent(topic, type) {
        var $btn      = $('#zorlinq32-ai-generate-btn');
        var $progress = $('#zorlinq32-ai-progress');
        $btn.prop('disabled', true).addClass('loading');
        $('#zorlinq32-ai-result').hide();
        showProgress($progress, '주제 분석 중...', 0);

        // ── 자연스럽게 차오르는 진행바 (20초 기준, 절대 감소 없음, 95% 상한) ──
        var _progCur  = 0;
        var _progDone = false;
        var _progTimer = null;

        // 메시지 타임아웃 (글 생성 로직 건드리지 않음 — 표시용만)
        var _msgs = [
            { at: 800,   msg: '주제 분석 중...' },
            { at: 4000,  msg: 'AI 글 작성 중...' },
            { at: 10000, msg: '문장 다듬는 중...' },
            { at: 16000, msg: 'SEO 최적화 중...' },
        ];
        _msgs.forEach(function(m) {
            setTimeout(function() {
                if (_progDone) return;
                $progress.find('.progress-label').text(m.msg);
            }, m.at);
        });

        // 300ms 마다 조금씩 증가: 20초 동안 0→90% (초당 4.5%)
        // 각 틱 +0.45% → 자연스러운 연속 증가, 100% 초과 없음
        _progTimer = setInterval(function() {
            if (_progDone) { clearInterval(_progTimer); return; }
            var cap = 90; // 완료 전 최대 90%
            if (_progCur >= cap) { clearInterval(_progTimer); return; }
            _progCur = Math.min(cap, _progCur + 0.45);
            safeProgress($progress, Math.round(_progCur), null);
        }, 100);

        $.ajax({
            url: zorlinq32AiWriter.ajaxUrl, type: 'POST', timeout: 200000,
            data: {
                action: 'zorlinq32_ai_generate', nonce: zorlinq32AiWriter.nonce, post_id: zorlinq32AiWriter.postId,
                topic: topic, type: type
            },
            success: function(response) {
                _progDone = true;
                clearInterval(_progTimer);
                safeProgress($progress, 100, '완료!');
                setTimeout(function() {
                    hideProgress($progress);
                    if (response.success && response.data) {
                        var d    = response.data;
                        var meta = d.meta_info || {};

                        // 에디터에 단일 클래식 블록으로 삽입
                        insertContentToEditor(d.html, d.title, meta);

                        // SEO 메타 숨김 필드 업데이트 (저장용)
                        if (meta.title)         $('#ai_seo_title').val(meta.title);
                        if (meta.meta_desc)     $('#ai_meta_desc').val(meta.meta_desc);
                        if (meta.slug)          $('#ai_slug').val(meta.slug);
                        if (meta.focus_keyword) $('#ai_focus_keyword').val(meta.focus_keyword);

                        var msg = '✅ 콘텐츠가 에디터에 삽입되었습니다!';
                        if (d.title)            msg += '<br>📝 <strong>SEO 제목:</strong> ' + escHtml(d.title);
                        if (meta.meta_desc)     msg += '<br>📄 <strong>메타 설명:</strong> ' + escHtml((meta.meta_desc||'').substring(0,60)) + '...';
                        if (meta.focus_keyword) msg += '<br>🎯 <strong>키워드:</strong> ' + escHtml(meta.focus_keyword);
                        if (meta.slug)          msg += '<br>🔗 <strong>슬러그:</strong> ' + escHtml(meta.slug);
                        msg += '<br><small style="color:#888;">💡 SEO 메타, Rank Math 자동 저장 완료</small>';
                        showResult(msg, 'success');
                    } else {
                        showResult('❌ ' + ((response.data && response.data.message) ? response.data.message : '생성 실패'), 'error');
                    }
                }, 500);
            },
            error: function(xhr, status) {
                hideProgress($progress);
                showResult('❌ ' + (status === 'timeout' ? '시간 초과. 다시 시도해주세요.' : '오류가 발생했습니다.'), 'error');
            },
            complete: function() { $btn.prop('disabled', false).removeClass('loading'); }
        });
    }

    /* ══════════════════════════════════════
       스키마 마크업 생성기 (멀티 스키마)
    ══════════════════════════════════════ */
    function initSchemaGenerator() {
        // PHP 렌더링된 기존 스키마를 JS Map에 초기화
        // ✅ v4.0: 항상 새로 초기화 (이전 잔여 데이터 제거)
        window._zorlinq32AiSchemaMap = {};
        $('#zorlinq32-ai-schema-list .zorlinq32-ai-schema-item').each(function() {
            var idx     = parseInt($(this).attr('data-index'));
            var type    = $(this).attr('data-type') || 'schema';
            var jsonStr = this.getAttribute('data-json') || '';
            window._zorlinq32AiSchemaMap[idx] = { json: jsonStr, type: type };
        });

        // 편집 모달 DOM 주입 (최초 1회)
        if (!$('#zorlinq32-ai-schema-modal').length) {
            $('body').append(
                '<div id="zorlinq32-ai-schema-modal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">' +
                    '<div style="background:#fff;border-radius:10px;width:92%;max-width:680px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.28);">' +
                        '<div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center;justify-content:space-between;">' +
                            '<strong style="font-size:15px;">스키마 편집</strong>' +
                            '<button id="zorlinq32-ai-schema-modal-close" type="button" style="background:none;border:none;font-size:20px;cursor:pointer;color:#555;line-height:1;">✕</button>' +
                        '</div>' +
                        '<div style="padding:16px 20px;flex:1;overflow:auto;">' +
                            '<p style="font-size:12px;color:#888;margin:0 0 8px;">JSON을 직접 편집하세요. 저장 시 유효성이 자동 검사됩니다.</p>' +
                            '<textarea id="zorlinq32-ai-schema-modal-textarea" spellcheck="false" style="width:100%;height:340px;font-family:monospace;font-size:12px;line-height:1.6;border:1px solid #ccd0d4;border-radius:6px;padding:10px;box-sizing:border-box;resize:vertical;white-space:pre;overflow-wrap:normal;overflow-x:auto;"></textarea>' +
                            '<p id="zorlinq32-ai-schema-modal-error" style="color:#c62828;font-size:12px;margin:6px 0 0;min-height:16px;"></p>' +
                        '</div>' +
                        '<div style="padding:12px 20px;border-top:1px solid #e0e0e0;display:flex;gap:10px;justify-content:flex-end;">' +
                            '<button id="zorlinq32-ai-schema-modal-format" type="button" class="zorlinq32-ai-small-btn" style="margin-right:auto;">🔧 JSON 정렬</button>' +
                            '<button id="zorlinq32-ai-schema-modal-save" type="button" class="zorlinq32-ai-button zorlinq32-ai-button--primary" style="padding:8px 24px;font-size:13px;">저장</button>' +
                            '<button id="zorlinq32-ai-schema-modal-cancel" type="button" class="zorlinq32-ai-small-btn" style="padding:8px 16px;">취소</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        }

        // 스키마 추가 생성 버튼
        $('#zorlinq32-ai-schema-generate-btn').on('click', function() {
            var schemaType = $('#zorlinq32-ai-schema-type').val();
            if (!schemaType) { setSchemaStatus('⚠️ 스키마 유형을 먼저 선택해주세요.', 'warn'); return; }
            generateSchema(schemaType);
        });

        // 전체 삭제 버튼
        $(document).on('click', '#zorlinq32-ai-schema-delete-all-btn', function() {
            if (!confirm('모든 스키마를 삭제하시겠습니까?')) return;
            deleteSchema(-1);
        });

        // 개별 삭제 버튼 (동적 요소 위임)
        $(document).on('click', '.zorlinq32-ai-schema-delete-single', function() {
            var idx = parseInt($(this).data('index'));
            if (!confirm('이 스키마를 삭제하시겠습니까?')) return;
            deleteSchema(idx);
        });

        // ── 편집 버튼 클릭 → 모달 열기 ──
        // ※ .attr('data-json') 사용: jQuery .data()는 JSON 문자열을 자동 파싱해
        //   객체로 반환하므로 JSON.parse()에 넘기면 [object Object]가 됨.
        //   .attr()는 항상 원시 문자열을 반환하므로 안전.
        $(document).on('click', '.zorlinq32-ai-schema-edit-single', function() {
            var $item = $(this).closest('.zorlinq32-ai-schema-item');
            var idx   = parseInt($item.attr('data-index'));
            var type  = $item.attr('data-type') || 'schema';

            // JS Map 우선 (renderSchemaList에서 저장) → PHP attr 폴백
            var mapEntry = window._zorlinq32AiSchemaMap && window._zorlinq32AiSchemaMap[idx];
            var jsonStr  = mapEntry ? (mapEntry.json || '') : ($item[0].getAttribute('data-json') || '');

            try {
                jsonStr = JSON.stringify(JSON.parse(jsonStr), null, 2);
            } catch(e) { /* 파싱 실패 시 원본 그대로 */ }

            $('#zorlinq32-ai-schema-modal-textarea').val(jsonStr);
            $('#zorlinq32-ai-schema-modal-error').text('');
            $('#zorlinq32-ai-schema-modal').data('edit-idx', idx).data('edit-type', type).css('display', 'flex');
            $('#zorlinq32-ai-schema-modal-textarea').focus();
        });

        // ── 모달 닫기 ──
        function closeModal() {
            $('#zorlinq32-ai-schema-modal').hide();
        }
        $(document).on('click', '#zorlinq32-ai-schema-modal-close, #zorlinq32-ai-schema-modal-cancel', closeModal);
        $(document).on('click', '#zorlinq32-ai-schema-modal', function(e) {
            if ($(e.target).is('#zorlinq32-ai-schema-modal')) closeModal();
        });

        // ── JSON 정렬 버튼 ──
        $(document).on('click', '#zorlinq32-ai-schema-modal-format', function() {
            var raw = $('#zorlinq32-ai-schema-modal-textarea').val();
            try {
                var parsed = JSON.parse(raw);
                $('#zorlinq32-ai-schema-modal-textarea').val(JSON.stringify(parsed, null, 2));
                $('#zorlinq32-ai-schema-modal-error').text('');
            } catch(e) {
                $('#zorlinq32-ai-schema-modal-error').text('❌ JSON 문법 오류: ' + e.message);
            }
        });

        // ── 저장 버튼 ──
        $(document).on('click', '#zorlinq32-ai-schema-modal-save', function() {
            var raw      = $('#zorlinq32-ai-schema-modal-textarea').val().trim();
            var editIdx  = $('#zorlinq32-ai-schema-modal').data('edit-idx');
            var editType = $('#zorlinq32-ai-schema-modal').data('edit-type') || 'schema';

            // JSON 유효성 검사
            var parsed;
            try {
                parsed = JSON.parse(raw);
            } catch(e) {
                $('#zorlinq32-ai-schema-modal-error').text('❌ JSON 문법 오류: ' + e.message);
                return;
            }
            $('#zorlinq32-ai-schema-modal-error').text('');

            var $saveBtn = $(this).prop('disabled', true).text('저장 중...');

            $.ajax({
                url:  zorlinq32AiWriter.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'zorlinq32_ai_save_schema_markup',
                    nonce:       zorlinq32AiWriter.nonce,
                    post_id:     zorlinq32AiWriter.postId,
                    schema_type: editType,
                    schema_json: JSON.stringify(parsed),
                    edit_idx:    editIdx
                },
                success: function(res) {
                    if (res.success) {
                        closeModal();
                        renderSchemaList(res.data.schemas || []);
                        setSchemaStatus('✅ 스키마가 수정되었습니다.', 'success');
                    } else {
                        $('#zorlinq32-ai-schema-modal-error').text('❌ ' + (res.data && res.data.message ? res.data.message : '저장 실패'));
                    }
                },
                error: function() {
                    $('#zorlinq32-ai-schema-modal-error').text('❌ 서버 오류. 다시 시도해주세요.');
                },
                complete: function() {
                    $saveBtn.prop('disabled', false).text('저장');
                }
            });
        });
    }

    function generateSchema(schemaType) {
        var $btn  = $('#zorlinq32-ai-schema-generate-btn');
        var $prog = $('#zorlinq32-ai-schema-progress');
        var $bar  = $('#zorlinq32-ai-schema-progress-bar');
        var $step = $('#zorlinq32-ai-schema-step');

        $btn.prop('disabled', true);
        $prog.show();
        setSchemaStatus('', '');

        var steps = [
            { text: '⏳ 스키마 분석 중...', pct: 20 },
            { text: '📡 API 요청 중...', pct: 45 },
            { text: '🔧 구조화 데이터 생성 중...', pct: 75 },
            { text: '✅ 완료!', pct: 100 },
        ];
        var si = 0;
        var stepInterval = setInterval(function() {
            if (si < steps.length - 1) {
                $step.text(steps[si].text);
                $bar.css('width', steps[si].pct + '%');
                si++;
            }
        }, 800);

        var editorContent = getEditorContent();

        $.ajax({
            url: zorlinq32AiWriter.ajaxUrl, type: 'POST', timeout: 90000,
            data: {
                action: 'zorlinq32_ai_generate_schema', nonce: zorlinq32AiWriter.nonce,
                post_id: zorlinq32AiWriter.postId, schema_type: schemaType, content: editorContent
            },
            success: function(res) {
                clearInterval(stepInterval);
                $step.text('✅ 완료!');
                $bar.css('width', '100%');
                setTimeout(function() {
                    $prog.hide();
                    $bar.css('width', '0%');
                    if (res.success && res.data) {
                        // 스키마 목록 갱신
                        renderSchemaList(res.data.schemas || []);
                        setSchemaStatus('✅ ' + schemaType.toUpperCase() + ' 스키마가 추가되었습니다!', 'success');
                    } else {
                        setSchemaStatus('❌ ' + ((res.data && res.data.message) ? res.data.message : '생성 실패'), 'error');
                    }
                }, 500);
            },
            error: function(xhr, status) {
                clearInterval(stepInterval);
                $prog.hide();
                $bar.css('width', '0%');
                setSchemaStatus('❌ ' + (status === 'timeout' ? '시간 초과. 다시 시도해주세요.' : '오류가 발생했습니다.'), 'error');
            },
            complete: function() { $btn.prop('disabled', false); }
        });
    }

    function deleteSchema(index) {
        $.ajax({
            url: zorlinq32AiWriter.ajaxUrl, type: 'POST',
            data: { action: 'zorlinq32_ai_delete_schema', nonce: zorlinq32AiWriter.nonce, post_id: zorlinq32AiWriter.postId, index: index },
            success: function(res) {
                if (res.success) {
                    if (res.data.all) {
                        renderSchemaList([]);
                        $('#zorlinq32-ai-schema-delete-all-btn').hide();
                        setSchemaStatus('🗑 모든 스키마가 삭제되었습니다.', 'info');
                    } else {
                        renderSchemaList(res.data.schemas || []);
                        setSchemaStatus('🗑 스키마가 삭제되었습니다.', 'info');
                    }
                } else {
                    setSchemaStatus('❌ ' + (res.data ? res.data.message : '삭제 실패'), 'error');
                }
            },
            error: function() { setSchemaStatus('❌ 오류가 발생했습니다.', 'error'); }
        });
    }

    function renderSchemaList(schemas) {
        var $list = $('#zorlinq32-ai-schema-list');
        $list.empty();
        var $delAll = $('#zorlinq32-ai-schema-delete-all-btn');

        if (!schemas || schemas.length === 0) {
            if ($delAll.length) $delAll.hide();
            return;
        }

        var typeLabels = {
            'article': '기사 (Article)', 'faq': 'FAQ', 'product_review': '상품리뷰',
            'schema': 'Schema'
        };
        // ✅ v4.0: 항상 완전 초기화 (renderSchemaList 호출마다 맵 리셋)
        window._zorlinq32AiSchemaMap = {};

        schemas.forEach(function(s, idx) {
            var label   = typeLabels[s.type] || s.type.toUpperCase();
            // json 필드 우선, 없으면 html에서 추출
            var jsonStr = s.json || '';
            if (!jsonStr && s.html) {
                var m = s.html.match(/<script[^>]*>([\s\S]*?)<\/script>/i);
                if (m) jsonStr = m[1].trim();
            }
            // JSON은 HTML attr에 넣지 않고 JS Map에 저장 (attr에 넣으면 " 가 HTML 파싱 깨뜨림)
            window._zorlinq32AiSchemaMap[idx] = { json: jsonStr, type: s.type };
            var $item = $(
                '<div class="zorlinq32-ai-schema-item" data-index="' + idx + '" data-type="' + escHtml(s.type) + '">' +
                    '<div class="zorlinq32-ai-schema-item-header">' +
                        '<span class="zorlinq32-ai-schema-item-label">✅ ' + escHtml(label) + ' 스키마</span>' +
                        '<div class="zorlinq32-ai-schema-item-actions">' +
                            '<button type="button" class="zorlinq32-ai-small-btn zorlinq32-ai-btn-edit zorlinq32-ai-schema-edit-single" data-index="' + idx + '">✏️ 편집</button>' +
                            '<button type="button" class="zorlinq32-ai-small-btn zorlinq32-ai-btn-danger zorlinq32-ai-schema-delete-single" data-index="' + idx + '">🗑 삭제</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
            $list.append($item);
        });

        if ($delAll.length) {
            $delAll.show();
        } else {
            $list.after('<button type="button" id="zorlinq32-ai-schema-delete-all-btn" class="zorlinq32-ai-small-btn zorlinq32-ai-btn-danger" style="margin-top:8px;width:100%;">🗑 전체 스키마 삭제</button>');
        }
    }

    function setSchemaStatus(msg, type) {
        var $s = $('#zorlinq32-ai-schema-status');
        var color = type === 'success' ? '#2e7d32' : (type === 'error' ? '#c62828' : (type === 'warn' ? '#e65100' : '#555'));
        $s.text(msg).css('color', color);
    }

    /* ══════════════════════════════════════════════════════════
       AI 썸네일 — 3단계 플로우 (JS 폴링 방식)
       ① 로컬: 주제 조사/콘셉트 기반 프롬프트 생성
       ② Google Flow: 브라우저에서 이미지 생성
       ③ Canvas: AI 이미지 위에 주제 텍스트 합성 후 WP 저장
    ══════════════════════════════════════════════════════════ */
    function initThumbnailGenerator() {
        $(document).on('click', '#zorlinq32-ai-thumb-generate-btn', function() {
            var $btn  = $(this);
            var topic = $.trim($('#zorlinq32-ai-thumb-topic').val()) || $.trim($('#zorlinq32-ai-topic').val());
            var style = $('#zorlinq32-ai-thumb-style').val() || 'poster';

            if (!topic) { thumbMsg('⚠️ 썸네일 주제를 입력해주세요.', 'warn'); return; }

            var postId = parseInt($('#post_ID').val(), 10) ||
                         (typeof zorlinq32AiWriter !== 'undefined' ? parseInt(zorlinq32AiWriter.postId, 10) : 0);
            if (!postId) { thumbMsg('❌ 글을 먼저 임시저장(Ctrl+S)한 후 다시 시도해주세요.', 'error'); return; }

            $btn.prop('disabled', true).text('⏳ 처리 중...');
            $('#zorlinq32-ai-thumb-progress').show();
            $('#zorlinq32-ai-thumb-preview').hide();
            thumbMsg('', '');

            /* ── 자체 템플릿 분기 ── */
            if ( style.indexOf('custom_tpl_') === 0 ) {
                var tplIdx  = parseInt(style.replace('custom_tpl_', ''), 10);
                var tplList = (typeof zorlinq32AiWriter !== 'undefined' && zorlinq32AiWriter.templates) ? zorlinq32AiWriter.templates : [];
                var tpl     = tplList[tplIdx];
                if (!tpl) {
                    thumbMsg('❌ 템플릿 데이터를 찾을 수 없습니다.', 'error');
                    resetBtn($btn);
                    return;
                }
                var titleText = $.trim($('#zorlinq32-ai-tpl-title-input').val());
                var subText   = $.trim($('#zorlinq32-ai-tpl-sub-input').val());
                if (!titleText) {
                    thumbMsg('⚠️ 제목을 입력해주세요.', 'warn');
                    resetBtn($btn);
                    return;
                }
                setProgress('🖼️ 템플릿 합성 중...', 2, 0, 0);
                $btn.text('🎨 Canvas 합성 중...');
                composeCustomTemplate(tpl, titleText, subText, topic, postId, $btn);
                return;
            }

            /* ── STEP 1: 프롬프트 생성 (AI 이미지) ── */
            setProgress('⏳ 프롬프트 생성 중...', 1, 0, 75);

            $.ajax({
                url: zorlinq32AiWriter.ajaxUrl, type: 'POST', timeout: 80000,
                data: { action: 'zorlinq32_ai_generate_image_prompt', nonce: zorlinq32AiWriter.nonce, topic: topic, style: style },
                success: function(res) {
                    var prompt    = (res.success && res.data && res.data.prompt)     ? res.data.prompt     : '';
                    // photo_realistic 스타일만 서버가 실제 negative_prompt를 채워 준다.
                    // Google Flow에서 프롬프트와 키워드 조사를 직접 처리합니다.
                    // 다른 스타일은 "피해야 할 것"이 이미 prompt 문장 안에 자연어로 녹여져 있으므로 빈 값이다.
                    var negPrompt = (res.success && res.data && res.data.neg_prompt) ? res.data.neg_prompt : '';
                    var visualMeta = (res.success && res.data && res.data.topic_research) ? res.data.topic_research : {};

                    if (!prompt) {
                        thumbMsg('❌ 프롬프트 생성 실패: ' + ((res.data && res.data.message) || '알 수 없는 오류'), 'error');
                        resetBtn($btn);
                        return;
                    }

                    setProgress('✅ 프롬프트 완성! 이미지 생성 중...', 2, 0, 110);
                    $btn.text('🎨 이미지 생성 중...');

                    /* ── STEP 2: UseAPI Google Flow background generation ── */
                    $.ajax({
                        url: zorlinq32AiWriter.ajaxUrl, type: 'POST', timeout: 190000,
                        data: { action: 'zorlinq32_ai_image_generate', nonce: zorlinq32AiWriter.nonce,
                                topic: topic, prompt: prompt, style: style, negative_prompt: negPrompt,
                                topic_research: JSON.stringify(visualMeta) },
                        success: function(r2) {
                            if (!r2.success || !r2.data || !r2.data.data_url) {
                                var errMsg = (r2.data && r2.data.message) ? r2.data.message : '이미지 생성 실패';
                                thumbMsg('❌ ' + errMsg, 'error');
                                resetBtn($btn);
                                return;
                            }
                            var usedModel = r2.data.model || 'Google Flow';
                            var sizeKb    = r2.data.size_kb || '?';
                            var mimeType  = (r2.data && r2.data.mime) ? r2.data.mime : '';
                            var isSvgOutput = mimeType === 'image/svg+xml';
                            var shouldSkipComposition = r2.data.provider === 'useapi_google_flow' || isSvgOutput || style === 'photo_realistic';

                            if (shouldSkipComposition) {
                                setProgress('✅ 이미지 완성! 미디어에 저장 중...', 3, 0, 0);
                                saveThumbToMedia(r2.data.data_url, topic, postId, $btn, usedModel, sizeKb, style, visualMeta);
                            } else {
                                setProgress('✅ 이미지 완성! 텍스트 합성 중...', 3, 0, 0);
                                composeImageWithTopic(r2.data.data_url, topic, style, visualMeta, function(composedDataUrl) {
                                    setProgress('💾 WordPress 미디어에 저장 중...', 3, 0, 0);
                                    saveThumbToMedia(composedDataUrl, topic, postId, $btn, usedModel, sizeKb, style, visualMeta);
                                });
                            }
                        },
                        error: function(xhr, status) {
                            thumbMsg('❌ 이미지 생성 오류: ' + status, 'error');
                            resetBtn($btn);
                        }
                    });

                },
                error: function(xhr, status) {
                    thumbMsg('❌ 이미지 프롬프트 생성 오류: ' + status, 'error');
                    resetBtn($btn);
                }
            });
        });
    }

    /* ── 결과 이미지 Canvas 합성 후 WP 저장 ── */

    /* ══════════════════════════════════════════════════════════
       Canvas 텍스트 오버레이 합성 — 스타일별 중앙 배치
       생성 이미지(배경 템플릿) 위에 주제 텍스트를 중앙에 합성
       • 모든 스타일: 텍스트 수평·수직 중앙 배치
       • 스타일별 텍스트 디자인 완전 차별화
       • photo_realistic: 이 함수 호출 안 함 (텍스트 없이 저장)
    ══════════════════════════════════════════════════════════ */
    function composeImageWithTopic(dataUrl, topic, style, visualMeta, callback) {
        visualMeta = visualMeta || {};
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            var W  = img.width  || 768;
            var H  = img.height || 512;
            var cv = document.createElement('canvas');
            cv.width = W; cv.height = H;
            var ctx = cv.getContext('2d');

            /* ── ① 생성 템플릿 이미지 렌더 ── */
            ctx.drawImage(img, 0, 0, W, H);

            /* ── ② 스타일별 + 키워드별 텍스트 설정 계산 (동적) ── */
            var textCfg = getTextConfig(style, W, H, topic, visualMeta);

            /* ── ③ 배경 오버레이 (스타일별) ── */
            if (textCfg.overlay) {
                var ovl = textCfg.overlay;
                if (ovl.type === 'bottom_fade') {
                    /* 하단 자연스러운 페이드 — photo_realistic용 */
                    var fadeH = Math.round(H * 0.45);
                    var fadeY = H - fadeH;
                    var fade = ctx.createLinearGradient(0, fadeY, 0, H);
                    fade.addColorStop(0,   'rgba(0,0,0,0)');
                    fade.addColorStop(0.4, 'rgba(0,0,0,0.45)');
                    fade.addColorStop(1,   'rgba(0,0,0,0.78)');
                    ctx.fillStyle = fade;
                    ctx.fillRect(0, fadeY, W, fadeH);
                }
                /* 'none' 및 그 외 — 오버레이 없음 */
            }

            /* ── ④ 텍스트 래핑 & 폰트 크기 자동 최적화 ── */
            var ff      = textCfg.fontFamily || "'Malgun Gothic','Apple SD Gothic Neo','Nanum Gothic','나눔고딕','맑은 고딕','Noto Sans KR',sans-serif";
            var maxTW   = Math.round(W * (textCfg.maxWidthRatio || 0.82));
            var px      = Math.round(H * (textCfg.fontSizeRatio || 0.10));
            var minPx   = Math.round(H * 0.040);
            var maxLines = textCfg.maxLines || 2;
            var lines;
            while (px >= minPx) {
                ctx.font = textCfg.fontWeight + ' ' + px + 'px ' + ff;
                lines = cvWrap(ctx, topic, maxTW, ctx.font);
                if (lines.length <= maxLines) break;
                px -= Math.round(H * 0.005);
            }
            lines = (lines || [topic]).slice(0, maxLines);

            /* 텍스트 길이에 따른 유동적 크기 미세 보정 — 짧은 키워드는 더 크게, 긴 키워드는 촘촘하게 */
            var avgLen = lines.reduce(function(s, l){ return s + l.length; }, 0) / lines.length;
            if (avgLen <= 5)       px = Math.round(px * 1.10);
            else if (avgLen >= 14) px = Math.round(px * 0.90);
            px = Math.max(minPx, px);

            var lh      = Math.round(px * (textCfg.lineHeightRatio || 1.3));
            var totalH  = lines.length * lh - Math.round(lh * 0.15);

            /* ── ⑤ 수직·수평 좌표 계산 ── */
            var cY;
            if (textCfg.textPositionY) {
                // bottom 위치 지정 (photo_realistic 등)
                cY = Math.round(H * textCfg.textPositionY) - Math.round(totalH / 2);
            } else {
                cY = Math.round((H - totalH) / 2) + Math.round(px * 0.1);
            }

            ctx.save();
            ctx.textBaseline = 'top';
            ctx.textAlign = 'center';

            if (textCfg.archLines) {
                /* 아치형 곡선 배치 — 타이포그래피, 한 줄일 때 */
                renderArchedText(ctx, lines[0], W, H, cY, totalH, px, textCfg, ff);
            } else {
                lines.forEach(function(ln, i) {
                    var x = W / 2;
                    var y = cY + i * lh;
                    /* 타이포그래피: 줄마다 리듬감 있는 크기 변화 */
                    var linePx = px;
                    if (textCfg.typoAccent && i === 1) linePx = Math.round(px * 0.78);
                    ctx.font = textCfg.fontWeight + ' ' + linePx + 'px ' + ff;

                    if (textCfg.typoAccent) {
                        renderTypoTextLine(ctx, ln, x, y, linePx, textCfg, W, i);
                    } else {
                        renderTextLine(ctx, ln, x, y, linePx, textCfg);
                    }
                });
            }

            /* ── ⑥ 스타일별 장식 요소 (포스터 하단 선, 미니멀 언더라인 등) ── */
            if (textCfg.decoration) {
                drawDecoration(ctx, textCfg.decoration, W, H, cY, totalH, px);
            }

            /* ── ⑦ 브랜딩 전용: CTA 배지 렌더 ── */
            if (textCfg.brandingBadge) {
                drawCtaBadge(ctx, W, H, cY, totalH, px, textCfg);
            }

            ctx.restore();
            // 요구사항: 썸네일은 항상 JPG로 저장(PNG보다 용량이 가볍고, 사진 계열
            // 콘텐츠에서는 화질 체감 차이가 거의 없음). 배경이 불투명하게 이미
            // ctx.drawImage로 채워졌으므로 JPEG 변환 시 투명도 손실 문제가 없다.
            //
            // 버그 수정: 원본 이미지가 CORS 헤더 없이 로드되면 canvas가
            // "오염(tainted)"되어 toDataURL() 호출 시 SecurityError가 발생한다.
            // 이 예외를 잡지 않으면 이미지 생성 자체는 성공했음에도 여기서
            // 조용히 멈춰버려 미리보기가 영영 나타나지 않는다(saveThumbToMedia
            // 호출까지 도달하지 못하므로 서버에도 저장되지 않음).
            // → try/catch로 감싸고, 실패 시 텍스트 합성 없이 원본 이미지로 폴백.
            try {
                callback(cv.toDataURL('image/jpeg', 0.92));
            } catch (e) {
                console.warn('Zorlinq32 AI: composeImageWithTopic canvas 오염(CORS)으로 텍스트 합성 실패, 원본 이미지로 대체 저장', e);
                callback(dataUrl);
            }
        };
        img.onerror = function() {
            console.warn('Zorlinq32 AI: composeImageWithTopic 이미지 로드 실패, 원본 사용');
            callback(dataUrl);
        };
        img.src = dataUrl;
    }

    /* ── HEX 유효성 검사 (배경 대비용 텍스트 색 결정에 사용) ── */
    function isHexColor(v) {
        return typeof v === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v.trim());
    }

    /* ── 감정 톤별 액센트 컬러 팔레트 (리서치 값이 없을 때의 폴백) ── */
    var TONE_ACCENTS = {
        urgent:      '#FF4D4D',
        trustworthy: '#3E7BFA',
        exciting:    '#FFD400',
        calm:        '#7FD1C0',
        dynamic:     '#FF8A3D',
        premium:     '#D4AF6A'
    };

    /* ══════════════════════════════════════════════════════════
       스타일별 텍스트 설정 반환 — 키워드/리서치 메타데이터 반영
       topic: 실제 입력 주제 (길이/어절수로 레이아웃 가변)
       meta:  Gemini 리서치 결과 (emotional_tone, text_color_hex,
              accent_color_hex, category 등)
    ══════════════════════════════════════════════════════════ */
    /* 2026-08 추가: 커스텀 AI 스타일(custom_style_*)의 id를 그 스타일이 상속한
       기본 스타일(base)로 변환한다. 텍스트 오버레이 레이아웃(getTextConfig)과
       Canvas 폴백 썸네일(generateCanvasThumbnail)은 항상 5개 기본 스타일 중
       하나만 알고 있으므로, 커스텀 스타일 id가 그대로 들어오면 configs[style]이
       undefined가 되어 매번 poster로 조용히 폴백된다 — 이를 방지하기 위해
       "실제 상속받은 base"로 미리 정규화해 의도한 레이아웃이 사용되게 한다. */
    function resolveBaseStyle(style) {
        style = style || 'poster';
        if (style.indexOf('custom_style_') === 0 &&
            typeof zorlinq32AiWriter !== 'undefined' &&
            zorlinq32AiWriter.customStyleBases &&
            zorlinq32AiWriter.customStyleBases[style]) {
            return zorlinq32AiWriter.customStyleBases[style];
        }
        return style;
    }

    function getTextConfig(style, W, H, topic, meta) {
        style = resolveBaseStyle(style);
        meta = meta || {};
        topic = topic || '';

        var tone       = meta.emotional_tone || 'dynamic';
        var accentHex  = isHexColor(meta.accent_color_hex) ? meta.accent_color_hex : (TONE_ACCENTS[tone] || TONE_ACCENTS.dynamic);
        var textHex    = isHexColor(meta.text_color_hex)   ? meta.text_color_hex   : '#ffffff';
        var wordCount  = topic.trim().split(/\s+/).filter(Boolean).length;
        var charCount  = topic.replace(/\s+/g, '').length;
        var isShort    = charCount <= 8;
        var isLong     = charCount >= 16;

        var configs = {
            /* ── 포스터: 히어로 문구처럼 역동적 — 짧으면 초대형+아치, 길면 강한 대비 2줄 ── */
            poster: {
                fontWeight: '900',
                fontSizeRatio: isShort ? 0.140 : (isLong ? 0.100 : 0.118),
                maxWidthRatio: isShort ? 0.72 : 0.82,
                lineHeightRatio: 1.22,
                maxLines: isLong ? 3 : 2,
                overlay: { type: 'none' },
                stroke: { color: 'rgba(0,0,0,0.95)', width: 0.10 },
                shadow: { color: 'rgba(0,0,0,0.9)', blur: 0.30, ox: 0.04, oy: 0.05 },
                fill: textHex,
                highlight: 'rgba(255,255,255,0.98)',
                decoration: { type: 'underline', color: accentHex, thickness: Math.max(3, Math.round(H*0.010)), padding: 0.055 },
                archLines: isShort && wordCount <= 2,
                arcDepth: 0.10
            },
            /* ── 미니멀: 가독성 최우선 — 배경 대비색 자동 계산, 장식 없음, 여백 넉넉 ── */
            minimal: {
                fontWeight: isShort ? '400' : '300',
                fontSizeRatio: isShort ? 0.100 : (isLong ? 0.072 : 0.085),
                maxWidthRatio: 0.66,
                lineHeightRatio: 1.55,
                maxLines: 2,
                overlay: { type: 'none' },
                stroke: { color: 'rgba(0,0,0,0.55)', width: 0.03 },
                shadow: { color: 'rgba(0,0,0,0.5)', blur: 0.10, ox: 0.006, oy: 0.010 },
                fill: textHex,
                highlight: null,
                decoration: { type: 'underline', color: 'rgba(255,255,255,0.55)', thickness: Math.max(1, Math.round(H*0.004)), padding: 0.070 }
            },
            /* ── 사실적 사진: bottom_fade 오버레이, 텍스트 하단 배치 ── */
            photo_realistic: {
                fontWeight: '700',
                fontSizeRatio: isLong ? 0.078 : 0.095,
                maxWidthRatio: 0.82,
                lineHeightRatio: 1.30,
                maxLines: isLong ? 3 : 2,
                overlay: { type: 'bottom_fade' },
                textPositionY: 0.80,
                stroke: { color: 'rgba(0,0,0,0.85)', width: 0.07 },
                shadow: { color: 'rgba(0,0,0,0.9)', blur: 0.22, ox: 0.025, oy: 0.035 },
                fill: '#ffffff',
                highlight: 'rgba(255,255,255,0.95)',
                decoration: null
            },
            /* ── 타이포그래피: 글자 유동성·곡률·리듬감이 핵심 — 짧으면 아치, 길면 크기 리듬 ── */
            typography: {
                fontWeight: '900',
                fontSizeRatio: isShort ? 0.150 : (isLong ? 0.100 : 0.128),
                maxWidthRatio: 0.90,
                lineHeightRatio: 1.20,
                maxLines: isLong ? 3 : 2,
                overlay: { type: 'none' },
                stroke: { color: 'rgba(0,0,0,0.98)', width: 0.09 },
                shadow: { color: 'rgba(0,0,0,1)', blur: 0.20, ox: 0.03, oy: 0.04 },
                fill: 'gradient_accent',
                accentHex: accentHex,
                highlight: null,
                typoAccent: true,
                decoration: null,
                /* 짧고 한 단어급 문구(1~2어절)는 아치형 곡선, 그 외에는 줄별 크기 리듬감 */
                archLines: isShort && wordCount <= 2,
                arcDepth: 0.14
            },
            /* ── 브랜딩: CTA 중심 — 문구 아래 실제 버튼형 배지를 그려 행동 유도 강조 ── */
            branding: {
                fontWeight: '800',
                fontSizeRatio: isShort ? 0.112 : (isLong ? 0.082 : 0.098),
                maxWidthRatio: 0.78,
                lineHeightRatio: 1.30,
                maxLines: isLong ? 3 : 2,
                overlay: { type: 'none' },
                stroke: { color: 'rgba(0,0,0,0.92)', width: 0.08 },
                shadow: { color: 'rgba(0,0,0,0.88)', blur: 0.25, ox: 0.03, oy: 0.04 },
                fill: '#ffffff',
                highlight: 'rgba(255,255,255,0.96)',
                decoration: null,
                brandingBadge: { accentHex: accentHex, label: pickCtaLabel(meta.category, tone) }
            }
        };
        return configs[style] || configs['poster'];
    }

    /* ── 카테고리/톤 기반 CTA 배지 라벨 자동 선택 ── */
    function pickCtaLabel(category, tone) {
        var byCategory = {
            '앱/서비스': '지금 다운로드',
            '금융': '지금 확인하기',
            '건강': '더 알아보기',
            '교육': '지금 시작하기',
            '제품': '자세히 보기',
            '엔터테인먼트': '지금 즐기기'
        };
        if (category && byCategory[category]) return byCategory[category];
        if (tone === 'urgent') return '지금 확인';
        return '자세히 보기';
    }

    /* ── 텍스트 라인 렌더링 (다중 패스) ── */
    function renderTextLine(ctx, text, x, y, px, cfg) {
        /* 패스 1: 스트로크 (외곽선) */
        if (cfg.stroke) {
            ctx.strokeStyle = cfg.stroke.color;
            ctx.lineWidth   = Math.max(2, Math.round(px * cfg.stroke.width));
            ctx.lineJoin    = 'round';
            ctx.shadowColor = 'transparent';
            ctx.strokeText(text, x, y);
        }
        /* 패스 2: 그림자 레이어 */
        if (cfg.shadow) {
            ctx.shadowColor   = cfg.shadow.color;
            ctx.shadowBlur    = Math.round(px * cfg.shadow.blur);
            ctx.shadowOffsetX = Math.round(px * cfg.shadow.ox);
            ctx.shadowOffsetY = Math.round(px * cfg.shadow.oy);
            ctx.fillStyle     = cfg.fill;
            ctx.fillText(text, x, y);
            ctx.shadowColor = 'transparent';
            ctx.shadowBlur  = 0;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;
        }
        /* 패스 3: 메인 색상 */
        ctx.fillStyle = cfg.fill;
        ctx.fillText(text, x, y);
        /* 패스 4: 하이라이트 */
        if (cfg.highlight) {
            ctx.fillStyle = cfg.highlight;
            ctx.fillText(text, x, y);
        }
    }

    /* ── 타이포그래피 전용 렌더링 (그라디에이션 + 크기 조절) ── */
    function renderTypoTextLine(ctx, text, x, y, px, cfg, W, lineIdx) {
        /* 패스 1: 두꺼운 다크 스트로크 */
        if (cfg.stroke) {
            ctx.strokeStyle = cfg.stroke.color;
            ctx.lineWidth   = Math.max(3, Math.round(px * cfg.stroke.width));
            ctx.lineJoin    = 'round';
            ctx.shadowColor = 'transparent';
            ctx.strokeText(text, x, y);
        }
        /* 패스 2: 그림자 */
        if (cfg.shadow) {
            ctx.shadowColor   = cfg.shadow.color;
            ctx.shadowBlur    = Math.round(px * cfg.shadow.blur);
            ctx.shadowOffsetX = Math.round(px * cfg.shadow.ox);
            ctx.shadowOffsetY = Math.round(px * cfg.shadow.oy);
            ctx.fillStyle     = '#ffffff';
            ctx.fillText(text, x, y);
            ctx.shadowColor   = 'transparent';
            ctx.shadowBlur    = 0;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;
        }
        /* 패스 3: 첫 줄 — 흰→액센트→흰 가로 그라디에이션 (키워드 감정톤에 맞는 색) */
        if (lineIdx === 0) {
            var metrics  = ctx.measureText(text);
            var tw       = metrics.width;
            var gx       = x - tw / 2;
            var accent   = (cfg && cfg.accentHex) || '#FFD700';
            var grad     = ctx.createLinearGradient(gx, 0, gx + tw, 0);
            grad.addColorStop(0,    'rgba(255,255,255,0.9)');
            grad.addColorStop(0.35, accent);
            grad.addColorStop(0.65, lightenHex(accent, 0.35));
            grad.addColorStop(1,    'rgba(255,255,255,0.9)');
            ctx.fillStyle = grad;
        } else {
            /* 둘째 줄 이하 — 리듬감을 위해 살짝 작고 밝은 회색톤 (getTextConfig에서 크기 조절) */
            ctx.fillStyle = 'rgba(220,220,220,0.92)';
        }
        ctx.fillText(text, x, y);
    }

    /* ── HEX 색상을 지정 비율만큼 밝게 (그라디언트 보조색 생성) ── */
    function lightenHex(hex, amt) {
        if (!isHexColor(hex)) return '#FFF0A0';
        var h = hex.replace('#', '');
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r = parseInt(h.substr(0,2), 16);
        var g = parseInt(h.substr(2,2), 16);
        var b = parseInt(h.substr(4,2), 16);
        r = Math.min(255, Math.round(r + (255 - r) * amt));
        g = Math.min(255, Math.round(g + (255 - g) * amt));
        b = Math.min(255, Math.round(b + (255 - b) * amt));
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    /* ══════════════════════════════════════════════════════════
       아치형 곡선 텍스트 배치 — 짧은 문구를 글자 하나씩
       완만한 원호를 따라 배치 (포스터/타이포그래피 공용)
    ══════════════════════════════════════════════════════════ */
    function renderArchedText(ctx, text, W, H, cY, totalH, px, cfg, ff) {
        var chars = text.split('');
        if (!chars.length) return;

        ctx.font = cfg.fontWeight + ' ' + px + 'px ' + ff;

        /* 각 글자 폭 측정 후, 문구 전체 너비 대비 완만한 아치 반지름 산정 */
        var gap = px * 0.05;
        var widths = chars.map(function(c){ return ctx.measureText(c).width; });
        var totalW = widths.reduce(function(s,w){ return s+w; }, 0) + (chars.length - 1) * gap;
        var arcDepth = (cfg.arcDepth || 0.10) * H;               // 아치가 휘는 최대 깊이
        var radius = (totalW * totalW) / (8 * arcDepth) + arcDepth / 2; // 현(chord) 공식 기반 반지름
        radius = Math.max(radius, totalW * 0.7);

        var pivotX = W / 2;
        var pivotY = cY + totalH / 2 + radius;   // 원의 중심 (텍스트 블록보다 아래쪽)
        var totalAngle = totalW / radius;         // 전체 호가 차지하는 각도(라디안)
        var startAngle = -Math.PI / 2 - totalAngle / 2; // 위쪽 정점 기준 좌우 대칭 시작각

        var accent = cfg.accentHex || (isHexColor(cfg.fill) ? cfg.fill : '#FFD700');
        var runningW = 0;

        chars.forEach(function(ch, i) {
            var charW = widths[i];
            var angle = startAngle + (runningW + charW / 2) / radius;
            runningW += charW + gap;

            var cx = pivotX + radius * Math.cos(angle);
            var cyPos = pivotY + radius * Math.sin(angle);

            ctx.save();
            ctx.translate(cx, cyPos);
            ctx.rotate(angle + Math.PI / 2); // 글자가 원호 접선 방향을 향하도록 회전
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            /* 스트로크 */
            if (cfg.stroke) {
                ctx.strokeStyle = cfg.stroke.color;
                ctx.lineWidth   = Math.max(2, Math.round(px * cfg.stroke.width));
                ctx.lineJoin    = 'round';
                ctx.shadowColor = 'transparent';
                ctx.strokeText(ch, 0, 0);
            }
            /* 그림자 + 채우기 */
            if (cfg.shadow) {
                ctx.shadowColor   = cfg.shadow.color;
                ctx.shadowBlur    = Math.round(px * cfg.shadow.blur);
                ctx.shadowOffsetX = Math.round(px * cfg.shadow.ox);
                ctx.shadowOffsetY = Math.round(px * cfg.shadow.oy);
            }
            ctx.fillStyle = isHexColor(cfg.fill) ? cfg.fill : accent;
            ctx.fillText(ch, 0, 0);
            ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0;
            ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;

            ctx.restore();
        });

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
    }

    /* ══════════════════════════════════════════════════════════
       브랜딩 스타일 전용 — CTA 버튼형 배지 렌더
       텍스트 아래에 알약형 배경 + 라벨 텍스트로 행동 유도 강조
    ══════════════════════════════════════════════════════════ */
    function drawCtaBadge(ctx, W, H, cY, totalH, px, cfg) {
        var badge = cfg.brandingBadge;
        if (!badge) return;
        var label = badge.label || '자세히 보기';
        var accent = badge.accentHex || '#FFD400';

        var badgeFontPx = Math.max(14, Math.round(px * 0.34));
        var ff = "'Malgun Gothic','Apple SD Gothic Neo','Nanum Gothic','나눔고딕','맑은 고딕','Noto Sans KR',sans-serif";
        ctx.font = '700 ' + badgeFontPx + 'px ' + ff;
        var textW = ctx.measureText(label).width;

        var padX = badgeFontPx * 1.1;
        var padY = badgeFontPx * 0.65;
        var badgeW = textW + padX * 2;
        var badgeH = badgeFontPx + padY * 2;
        var badgeX = W/2 - badgeW/2;
        var badgeY = cY + totalH + Math.round(H * 0.045);
        var radius = badgeH / 2;

        /* 알약형 배경 */
        ctx.save();
        ctx.shadowColor = 'rgba(0,0,0,0.35)';
        ctx.shadowBlur  = Math.round(badgeH * 0.25);
        ctx.shadowOffsetY = Math.round(badgeH * 0.08);
        ctx.fillStyle = accent;
        roundRectPath(ctx, badgeX, badgeY, badgeW, badgeH, radius);
        ctx.fill();
        ctx.restore();

        /* 라벨 텍스트 (배경 밝기에 따라 자동 대비) */
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = hexIsLight(accent) ? '#1a1a1a' : '#ffffff';
        ctx.fillText(label, W/2, badgeY + badgeH/2 + badgeFontPx * 0.03);
        ctx.restore();
    }

    /* ── 둥근 사각형 경로 (CTA 배지용) ── */
    function roundRectPath(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y,     x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x,     y + h, r);
        ctx.arcTo(x,     y + h, x,     y,     r);
        ctx.arcTo(x,     y,     x + w, y,     r);
        ctx.closePath();
    }

    /* ── HEX 색상의 밝기 판정 (라벨 텍스트 흑/백 자동 선택) ── */
    function hexIsLight(hex) {
        if (!isHexColor(hex)) return true;
        var h = hex.replace('#', '');
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r = parseInt(h.substr(0,2), 16);
        var g = parseInt(h.substr(2,2), 16);
        var b = parseInt(h.substr(4,2), 16);
        var luminance = (0.299*r + 0.587*g + 0.114*b) / 255;
        return luminance > 0.6;
    }

    /* ── 장식 요소 그리기 ── */
    function drawDecoration(ctx, dec, W, H, cY, totalH, px) {
        if (dec.type === 'h_lines') {
            /* 텍스트 블록 위아래 수평선 */
            var pad  = Math.round(H * dec.padding);
            var lineY1 = cY - pad;
            var lineY2 = cY + totalH + pad;
            var lineW  = Math.round(W * 0.55);
            var lineX  = Math.round((W - lineW) / 2);
            ctx.strokeStyle = dec.color;
            ctx.lineWidth   = dec.thickness;
            ctx.beginPath(); ctx.moveTo(lineX, lineY1); ctx.lineTo(lineX + lineW, lineY1); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(lineX, lineY2); ctx.lineTo(lineX + lineW, lineY2); ctx.stroke();
        } else if (dec.type === 'underline') {
            /* 단일 언더라인 */
            var ulPad = Math.round(H * dec.padding);
            var ulW   = Math.round(W * 0.35);
            var ulX   = Math.round((W - ulW) / 2);
            var ulY   = cY + totalH + ulPad;
            ctx.strokeStyle = dec.color;
            ctx.lineWidth   = dec.thickness;
            ctx.beginPath(); ctx.moveTo(ulX, ulY); ctx.lineTo(ulX + ulW, ulY); ctx.stroke();
        } else if (dec.type === 'typo_lines') {
            /* 타이포그래피 전폭 선 (5%~95%) */
            var tPad  = Math.round(H * dec.padding);
            var tY1   = cY - tPad;
            var tY2   = cY + totalH + tPad;
            var tX1   = Math.round(W * 0.05);
            var tX2   = Math.round(W * 0.95);
            ctx.strokeStyle = dec.color;
            ctx.lineWidth   = dec.thickness;
            ctx.beginPath(); ctx.moveTo(tX1, tY1); ctx.lineTo(tX2, tY1); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(tX1, tY2); ctx.lineTo(tX2, tY2); ctx.stroke();
        }
    }

    /* ══════════════════════════════════════════════════════════
       자체 썸네일 템플릿 Canvas 합성
       tpl: 설정에서 저장된 템플릿 데이터 (preview_url, title_x/y/w/h, sub_x/y/w/h)
       titleText / subText: 포스트 편집기에서 사용자가 직접 입력한 텍스트
    ══════════════════════════════════════════════════════════ */
    function composeCustomTemplate(tpl, titleText, subText, topic, postId, $btn) {
        var img = new Image();
        img.crossOrigin = 'anonymous';

        img.onload = function() {
            var W = img.naturalWidth  || 1200;
            var H = img.naturalHeight || 630;
            var cv  = document.createElement('canvas');
            cv.width = W; cv.height = H;
            var ctx = cv.getContext('2d');

            /* ① 배경 이미지 렌더 */
            ctx.drawImage(img, 0, 0, W, H);

            /* ② 제목 텍스트 합성 */
            if (titleText) {
                drawTemplateText(ctx, titleText,
                    parseFloat(tpl.title_x || 0.05) * W,
                    parseFloat(tpl.title_y || 0.55) * H,
                    parseFloat(tpl.title_w || 0.90) * W,
                    parseFloat(tpl.title_h || 0.25) * H,
                    true);
            }

            /* ③ 부제목 텍스트 합성 */
            if (subText) {
                drawTemplateText(ctx, subText,
                    parseFloat(tpl.sub_x || 0.05) * W,
                    parseFloat(tpl.sub_y || 0.80) * H,
                    parseFloat(tpl.sub_w || 0.90) * W,
                    parseFloat(tpl.sub_h || 0.12) * H,
                    false);
            }

            setProgress('💾 WordPress 미디어에 저장 중...', 3, 0, 0);
            // 요구사항: 항상 JPG로 저장 (템플릿 배경도 불투명하게 렌더되므로 투명도 손실 없음)
            //
            // 버그 수정: crossOrigin='anonymous'로 로드했더라도 템플릿 이미지 서버가
            // Access-Control-Allow-Origin 헤더를 내려주지 않으면 img.onload는 정상
            // 실행되지만 canvas가 "오염(tainted)"된 상태가 되어 toDataURL() 호출 시
            // SecurityError 예외가 발생한다. 이전에는 이 예외를 잡지 않아서
            // saveThumbToMedia()가 아예 호출되지 못하고 조용히 멈췄다(진행 표시줄만
            // 뜬 채로 미리보기도, 에러 메시지도 나타나지 않는 원인).
            // → try/catch로 감싸고 실패 시 CORS 우회 재시도 경로로 넘긴다.
            try {
                saveThumbToMedia(cv.toDataURL('image/jpeg', 0.92), titleText || topic, postId, $btn, tpl.name + ' (자체 템플릿)', null, 'custom_template', {});
            } catch (e) {
                console.warn('Zorlinq32 AI: composeCustomTemplate canvas 오염(CORS), crossOrigin 없이 재시도', e);
                retryTemplateWithoutCrossOrigin();
            }
        };

        img.onerror = function() {
            retryTemplateWithoutCrossOrigin();
        };

        /* crossOrigin 속성 없이 이미지를 다시 로드해 저장을 재시도한다.
           (일부 이미지 호스팅은 CORS 헤더가 없어도 <img> 태그 자체는 렌더 가능하지만,
           crossOrigin='anonymous' 지정 시에는 헤더 부재로 로드/캔버스 사용이 막힌다.) */
        function retryTemplateWithoutCrossOrigin() {
            var img2 = new Image();
            img2.onload = function() {
                var W = img2.naturalWidth || 1200, H = img2.naturalHeight || 630;
                var cv2 = document.createElement('canvas');
                cv2.width = W; cv2.height = H;
                var ctx2 = cv2.getContext('2d');
                try {
                    ctx2.drawImage(img2, 0, 0, W, H);
                    if (titleText) drawTemplateText(ctx2, titleText,
                        parseFloat(tpl.title_x||0.05)*W, parseFloat(tpl.title_y||0.55)*H,
                        parseFloat(tpl.title_w||0.90)*W, parseFloat(tpl.title_h||0.25)*H, true);
                    if (subText) drawTemplateText(ctx2, subText,
                        parseFloat(tpl.sub_x||0.05)*W, parseFloat(tpl.sub_y||0.80)*H,
                        parseFloat(tpl.sub_w||0.90)*W, parseFloat(tpl.sub_h||0.12)*H, false);
                    saveThumbToMedia(cv2.toDataURL('image/jpeg', 0.92), titleText || topic, postId, $btn, tpl.name, null, 'custom_template', {});
                } catch(e) {
                    thumbMsg('❌ 템플릿 이미지 CORS 오류로 텍스트 합성이 불가능합니다. 미디어 라이브러리에 직접 업로드한 이미지를 템플릿으로 사용하거나, 이미지가 호스팅된 서버에서 CORS 헤더(Access-Control-Allow-Origin)를 허용해주세요.', 'error');
                    resetBtn($btn);
                }
            };
            img2.onerror = function() {
                thumbMsg('❌ 템플릿 이미지를 불러올 수 없습니다. (URL: ' + tpl.preview_url + ')', 'error');
                resetBtn($btn);
            };
            img2.src = tpl.preview_url;
        }

        img.src = tpl.preview_url;
    }

    /* ── 템플릿 텍스트 지정 영역에 Canvas 렌더링 ── */
    function drawTemplateText(ctx, text, areaX, areaY, areaW, areaH, isTitle) {
        if (!text || !text.trim()) return;
        var ff = "'Malgun Gothic','Apple SD Gothic Neo','Nanum Gothic','나눔고딕','맑은 고딕','Noto Sans KR',sans-serif";
        var fontWeight = isTitle ? '900' : '700';
        var px    = Math.max(isTitle ? 14 : 10, Math.round(areaH * 0.45));
        var minPx = isTitle ? 14 : 10;
        var lines, lh, totalH;

        while (px >= minPx) {
            ctx.font = fontWeight + ' ' + px + 'px ' + ff;
            lines  = cvWrap(ctx, text, areaW, ctx.font);
            lh     = Math.round(px * 1.28);
            totalH = lines.length * lh;
            if (totalH <= areaH) break;
            px -= 2;
        }
        lines  = lines || [text];
        lh     = Math.round(px * 1.28);
        totalH = lines.length * lh;

        var startY  = areaY + (areaH - totalH) / 2;
        var centerX = areaX + areaW / 2;

        ctx.save();
        ctx.font = fontWeight + ' ' + px + 'px ' + ff;
        ctx.textBaseline = 'top';
        ctx.textAlign    = 'center';

        lines.forEach(function(ln, i) {
            var y = startY + i * lh;
            // 외곽선
            ctx.strokeStyle = 'rgba(0,0,0,0.85)';
            ctx.lineWidth   = Math.max(2, Math.round(px * 0.07));
            ctx.lineJoin    = 'round';
            ctx.shadowColor = 'transparent';
            ctx.strokeText(ln, centerX, y);
            // 그림자+채우기
            ctx.shadowColor   = 'rgba(0,0,0,0.80)';
            ctx.shadowBlur    = Math.round(px * 0.22);
            ctx.shadowOffsetX = Math.round(px * 0.025);
            ctx.shadowOffsetY = Math.round(px * 0.035);
            ctx.fillStyle = isTitle ? '#ffffff' : 'rgba(235,235,235,0.96)';
            ctx.fillText(ln, centerX, y);
            ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0;
            ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
            // 메인
            ctx.fillStyle = isTitle ? '#ffffff' : 'rgba(230,230,230,0.95)';
            ctx.fillText(ln, centerX, y);
        });
        ctx.restore();
    }

    function resetBtn($btn) {
        $btn.prop('disabled', false).text('🖼️ 썸네일 생성');
    }

    /* ── Canvas 폴백 (현재 미사용 — 코드 보존만) ── */
    function canvasFallback(topic, style, postId, $btn) {
        setProgress('🎨 Canvas로 이미지 생성 중... (폴백)', 0, 0, 0);
        var dataUrl = generateCanvasThumbnail(topic, style);
        saveThumbToMedia(dataUrl, topic, postId, $btn, null, null, style, {});
    }

    /* ── WordPress 미디어 저장 (Canvas 합성 완료 후) ── */
    function saveThumbToMedia(dataUrl, topic, postId, $btn, model, sizeKb, style, visualMeta) {
        setProgress('💾 WordPress 미디어 라이브러리에 저장 중...', 3, 0, 0);
        visualMeta = visualMeta || {};
        $.ajax({
            url:     zorlinq32AiWriter.ajaxUrl,
            type:    'POST',
            timeout: 30000,
            data: {
                action:         'zorlinq32_ai_generate_thumbnail',
                nonce:          zorlinq32AiWriter.nonce,
                post_id:        postId,
                topic:          topic,
                image_data:     dataUrl,
                // 대체 텍스트(alt) 자동 생성을 위해 스타일 라벨과 조사 메타데이터를 함께 전달
                style:          style || '',
                actual_meaning: visualMeta.actual_meaning || '',
                visual_context: visualMeta.visual_context || '',
                category:       visualMeta.category || ''
            },
            success: function(res) {
                $('#zorlinq32-ai-thumb-progress').hide();
                if (res.success && res.data && res.data.url) {
                    $('#zorlinq32-ai-thumb-img').attr('src', res.data.url + '?t=' + Date.now());
                    $('#zorlinq32-ai-thumb-preview').fadeIn(300);
                    var isSvgSaved = typeof dataUrl === 'string' && dataUrl.indexOf('data:image/svg+xml') === 0;
                    var saveMessage = isSvgSaved
                        ? '✅ 썸네일 완성! SVG 벡터 아트가 저장되었습니다.'
                        : '✅ 썸네일 완성! 대표 이미지로 설정됨.';
                    thumbMsg(saveMessage + (model ? ' (' + model + ')' : ''), 'success');
                    try {
                        if (wp && wp.media && wp.media.featuredImage)
                            wp.media.featuredImage.set(res.data.attachment_id);
                    } catch(e) {}
                } else {
                    thumbMsg('❌ 저장 실패: ' + ((res.data && res.data.message) || '알 수 없는 오류'), 'error');
                }
            },
            error: function(xhr, status) {
                $('#zorlinq32-ai-thumb-progress').hide();
                thumbMsg('❌ 미디어 저장 오류: ' + status, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('🖼️ 썸네일 생성');
            }
        });
    }

    /* ── 3단계 진행 표시 + 진행률 바 ── */
    function setProgress(txt, step, elapsed, maxWait) {
        var stepHtml = '';
        if (step && step > 0) {
            var steps = [
                { n:1, icon:'✍️', label:'프롬프트' },
                { n:2, icon:'🎨', label:'이미지 생성' },
                { n:3, icon:'💾', label:'저장' }
            ];
            stepHtml = '<div style="display:flex;gap:5px;margin-bottom:7px;justify-content:center;">';
            steps.forEach(function(s) {
                var done  = step > s.n;
                var active = step === s.n;
                var bg    = done ? '#4caf50' : active ? '#1565c0' : '#bdbdbd';
                var color = (done || active) ? '#fff' : '#666';
                stepHtml += '<span style="font-size:10px;padding:3px 9px;border-radius:12px;background:' + bg + ';color:' + color + ';font-weight:600;">'
                    + s.icon + ' ' + s.label + (done ? ' ✓' : (active ? ' ●' : ''))
                    + '</span>';
            });
            stepHtml += '</div>';
        }
        var barHtml = '';
        if (maxWait && maxWait > 0 && elapsed >= 0) {
            var pct = Math.min(98, Math.round((elapsed / maxWait) * 100));
            barHtml = '<div style="height:4px;background:#e0e0e0;border-radius:2px;overflow:hidden;margin-bottom:5px;">'
                + '<div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,#1565c0,#42a5f5);transition:width 0.5s;"></div></div>';
        }
        $('#zorlinq32-ai-thumb-progress-text').html(stepHtml + barHtml + '<span style="font-size:11px;color:#444;">' + txt + '</span>');
    }

    /* ══════════════════════════════════════════════════════════
       Canvas 생성 — 각 스타일별 40가지 이상의 고유한 배경 조합
       매번 다른 랜덤 배경 생성 (중복 방지)
    ══════════════════════════════════════════════════════════ */
    function generateCanvasThumbnail(topic, style) {
        style = resolveBaseStyle(style);
        var W = 1200, H = 630;
        var cv  = document.createElement('canvas');
        cv.width = W; cv.height = H;
        var ctx = cv.getContext('2d');

        // 각 스타일별 40가지 이상의 고유한 배경 조합
        var styleVariants = {
            poster: [
                { bg:[['#1a237e',0],['#4a148c',.5],['#880e4f',1]], text:'#fff', accent:'#ffeb3b', shapes:'rects' },
                { bg:[['#b71c1c',0],['#d32f2f',.5],['#f44336',1]], text:'#fff', accent:'#ffd54f', shapes:'circles' },
                { bg:[['#004d40',0],['#00695c',.5],['#00897b',1]], text:'#fff', accent:'#64ffda', shapes:'rects' },
                { bg:[['#e65100',0],['#ff6f00',.5],['#ff9800',1]], text:'#fff', accent:'#fff9c4', shapes:'rects' },
                { bg:[['#1565c0',0],['#1976d2',.5],['#2196f3',1]], text:'#fff', accent:'#ffeb3b', shapes:'circles' },
                { bg:[['#6a1b9a',0],['#7b1fa2',.5],['#9c27b0',1]], text:'#fff', accent:'#f8bbd0', shapes:'rects' },
                { bg:[['#c62828',0],['#d32f2f',.5],['#e53935',1]], text:'#fff', accent:'#fff59d', shapes:'dots' },
                { bg:[['#283593',0],['#303f9f',.5],['#3949ab',1]], text:'#fff', accent:'#ffeb3b', shapes:'rects' },
                { bg:[['#ad1457',0],['#c2185b',.5],['#d81b60',1]], text:'#fff', accent:'#fff176', shapes:'circles' },
                { bg:[['#00838f',0],['#0097a7',.5],['#00acc1',1]], text:'#fff', accent:'#ffeb3b', shapes:'rects' },
                { bg:[['#4a148c',0],['#6a1b9a',.5],['#7b1fa2',1]], text:'#fff', accent:'#ce93d8', shapes:'hexs' },
                { bg:[['#f57f17',0],['#f9a825',.5],['#fbc02d',1]], text:'#263238', accent:'#ff6f00', shapes:'rects' },
                { bg:[['#01579b',0],['#0277bd',.5],['#0288d1',1]], text:'#fff', accent:'#ffeb3b', shapes:'circles' },
                { bg:[['#33691e',0],['#558b2f',.5],['#689f38',1]], text:'#fff', accent:'#ffeb3b', shapes:'rects' },
                { bg:[['#bf360c',0],['#d84315',.5],['#e64a19',1]], text:'#fff', accent:'#fff59d', shapes:'rects' },
                { bg:[['#1a237e',0],['#283593',.5],['#3949ab',1]], text:'#fff', accent:'#80deea', shapes:'dots' },
                { bg:[['#880e4f',0],['#ad1457',.5],['#c2185b',1]], text:'#fff', accent:'#f8bbd0', shapes:'circles' },
                { bg:[['#004d40',0],['#00695c',.5],['#00796b',1]], text:'#fff', accent:'#64ffda', shapes:'rects' },
                { bg:[['#e65100',0],['#ef6c00',.5],['#f57c00',1]], text:'#fff', accent:'#fff9c4', shapes:'waves' },
                { bg:[['#0d47a1',0],['#1565c0',.5],['#1976d2',1]], text:'#fff', accent:'#ffeb3b', shapes:'rects' },
                { bg:[['#4a148c',0],['#6a1b9a',.5],['#8e24aa',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#b71c1c',0],['#c62828',.5],['#d32f2f',1]], text:'#fff', accent:'#ffecb3', shapes:'rects' },
                { bg:[['#006064',0],['#00838f',.5],['#0097a7',1]], text:'#fff', accent:'#ffeb3b', shapes:'dots' },
                { bg:[['#f57f17',0],['#f9a825',.5],['#fdd835',1]], text:'#1a237e', accent:'#ff6f00', shapes:'lines' },
                { bg:[['#263238',0],['#37474f',.5],['#455a64',1]], text:'#fff', accent:'#80deea', shapes:'rects' },
                { bg:[['#1b5e20',0],['#2e7d32',.5],['#388e3c',1]], text:'#fff', accent:'#c5e1a5', shapes:'leaves' },
                { bg:[['#4a148c',0],['#6a1b9a',.5],['#7b1fa2',1]], text:'#fff', accent:'#ba68c8', shapes:'hexs' },
                { bg:[['#b71c1c',0],['#c62828',.5],['#d32f2f',1]], text:'#fff', accent:'#fff59d', shapes:'circles' },
                { bg:[['#01579b',0],['#0277bd',.5],['#0288d1',1]], text:'#fff', accent:'#80deea', shapes:'rects' },
                { bg:[['#e65100',0],['#ef6c00',.5],['#f57c00',1]], text:'#fff', accent:'#ffe57f', shapes:'rects' },
                { bg:[['#311b92',0],['#4527a0',.5],['#512da8',1]], text:'#fff', accent:'#b39ddb', shapes:'circles' },
                { bg:[['#880e4f',0],['#ad1457',.5],['#c2185b',1]], text:'#fff', accent:'#f48fb1', shapes:'rects' },
                { bg:[['#004d40',0],['#00695c',.5],['#00897b',1]], text:'#fff', accent:'#4db6ac', shapes:'dots' },
                { bg:[['#f57f17',0],['#f9a825',.5],['#fbc02d',1]], text:'#263238', accent:'#ff8f00', shapes:'waves' },
                { bg:[['#1a237e',0],['#283593',.5],['#303f9f',1]], text:'#fff', accent:'#7986cb', shapes:'rects' },
                { bg:[['#b71c1c',0],['#d32f2f',.5],['#e53935',1]], text:'#fff', accent:'#ffcc80', shapes:'circles' },
                { bg:[['#006064',0],['#00838f',.5],['#00acc1',1]], text:'#fff', accent:'#4dd0e1', shapes:'rects' },
                { bg:[['#e65100',0],['#ff6f00',.5],['#ff8f00',1]], text:'#fff', accent:'#ffe082', shapes:'rects' },
                { bg:[['#4a148c',0],['#6a1b9a',.5],['#8e24aa',1]], text:'#fff', accent:'#ce93d8', shapes:'hexs' },
                { bg:[['#1b5e20',0],['#2e7d32',.5],['#43a047',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#01579b',0],['#0277bd',.5],['#039be5',1]], text:'#fff', accent:'#29b6f6', shapes:'circles' },
                { bg:[['#bf360c',0],['#d84315',.5],['#f4511e',1]], text:'#fff', accent:'#ffab91', shapes:'rects' },
                { bg:[['#311b92',0],['#4527a0',.5],['#5e35b1',1]], text:'#fff', accent:'#9575cd', shapes:'dots' }
            ],
            minimal: [
                { bg:[['#f0f4f8',0],['#dce8f3',1]], text:'#1a2340', accent:'#1565c0', shapes:'lines' },
                { bg:[['#fafafa',0],['#eceff1',1]], text:'#263238', accent:'#0277bd', shapes:'lines' },
                { bg:[['#ffffff',0],['#f5f5f5',1]], text:'#212121', accent:'#1976d2', shapes:'lines' },
                { bg:[['#f5f5f5',0],['#e0e0e0',1]], text:'#424242', accent:'#0288d1', shapes:'lines' },
                { bg:[['#fafafa',0],['#e8eaf6',1]], text:'#1a237e', accent:'#3949ab', shapes:'lines' },
                { bg:[['#f9fbe7',0],['#f0f4c3',1]], text:'#33691e', accent:'#689f38', shapes:'lines' },
                { bg:[['#fff3e0',0],['#ffe0b2',1]], text:'#e65100', accent:'#ff6f00', shapes:'lines' },
                { bg:[['#fce4ec',0],['#f8bbd0',1]], text:'#880e4f', accent:'#c2185b', shapes:'lines' },
                { bg:[['#e0f2f1',0],['#b2dfdb',1]], text:'#004d40', accent:'#00695c', shapes:'lines' },
                { bg:[['#e8f5e9',0],['#c8e6c9',1]], text:'#1b5e20', accent:'#2e7d32', shapes:'lines' },
                { bg:[['#f3e5f5',0],['#e1bee7',1]], text:'#4a148c', accent:'#6a1b9a', shapes:'lines' },
                { bg:[['#e3f2fd',0],['#bbdefb',1]], text:'#0d47a1', accent:'#1565c0', shapes:'lines' },
                { bg:[['#fff8e1',0],['#ffecb3',1]], text:'#f57f17', accent:'#f9a825', shapes:'lines' },
                { bg:[['#fbe9e7',0],['#ffccbc',1]], text:'#bf360c', accent:'#d84315', shapes:'lines' },
                { bg:[['#efebe9',0],['#d7ccc8',1]], text:'#3e2723', accent:'#5d4037', shapes:'lines' },
                { bg:[['#f1f8e9',0],['#dcedc8',1]], text:'#33691e', accent:'#558b2f', shapes:'lines' },
                { bg:[['#e0f7fa',0],['#b2ebf2',1]], text:'#006064', accent:'#00838f', shapes:'lines' },
                { bg:[['#f9fbe7',0],['#f0f4c3',1]], text:'#827717', accent:'#9e9d24', shapes:'lines' },
                { bg:[['#fff9c4',0],['#fff59d',1]], text:'#f57f17', accent:'#f9a825', shapes:'lines' },
                { bg:[['#fffde7',0],['#fff9c4',1]], text:'#f57f17', accent:'#fbc02d', shapes:'lines' },
                { bg:[['#ffffff',0],['#f5f5f5',1]], text:'#424242', accent:'#616161', shapes:'lines' },
                { bg:[['#fafafa',0],['#eeeeee',1]], text:'#212121', accent:'#616161', shapes:'lines' },
                { bg:[['#f5f5f5',0],['#e0e0e0',1]], text:'#263238', accent:'#546e7a', shapes:'lines' },
                { bg:[['#eceff1',0],['#cfd8dc',1]], text:'#263238', accent:'#455a64', shapes:'lines' },
                { bg:[['#ffffff',0],['#fafafa',1]], text:'#212121', accent:'#424242', shapes:'lines' },
                { bg:[['#f5f5f5',0],['#eeeeee',1]], text:'#424242', accent:'#757575', shapes:'lines' },
                { bg:[['#fafafa',0],['#f0f0f0',1]], text:'#263238', accent:'#546e7a', shapes:'lines' },
                { bg:[['#ffffff',0],['#f8f9fa',1]], text:'#212121', accent:'#495057', shapes:'lines' },
                { bg:[['#f8f9fa',0],['#e9ecef',1]], text:'#343a40', accent:'#495057', shapes:'lines' },
                { bg:[['#ffffff',0],['#f7f7f7',1]], text:'#333333', accent:'#555555', shapes:'lines' },
                { bg:[['#fcfcfc',0],['#f0f0f0',1]], text:'#222222', accent:'#444444', shapes:'lines' },
                { bg:[['#fefefe',0],['#f5f5f5',1]], text:'#1a1a1a', accent:'#333333', shapes:'lines' },
                { bg:[['#ffffff',0],['#f2f2f2',1]], text:'#2c3e50', accent:'#34495e', shapes:'lines' },
                { bg:[['#fafbfc',0],['#eceff1',1]], text:'#263238', accent:'#37474f', shapes:'lines' },
                { bg:[['#f9f9f9',0],['#efefef',1]], text:'#212121', accent:'#424242', shapes:'lines' },
                { bg:[['#ffffff',0],['#f6f6f6',1]], text:'#1e1e1e', accent:'#383838', shapes:'lines' },
                { bg:[['#fdfdfd',0],['#f3f3f3',1]], text:'#262626', accent:'#404040', shapes:'lines' },
                { bg:[['#fefefe',0],['#f4f4f4',1]], text:'#2a2a2a', accent:'#454545', shapes:'lines' },
                { bg:[['#ffffff',0],['#f1f1f1',1]], text:'#1c1c1c', accent:'#363636', shapes:'lines' },
                { bg:[['#fafafa',0],['#e8e8e8',1]], text:'#242424', accent:'#424242', shapes:'lines' },
                { bg:[['#f8f8f8',0],['#ebebeb',1]], text:'#2e2e2e', accent:'#4a4a4a', shapes:'lines' },
                { bg:[['#fcfcfc',0],['#ededed',1]], text:'#202020', accent:'#3c3c3c', shapes:'lines' }
            ],
            infographic: [
                { bg:[['#0277bd',0],['#00838f',.5],['#00695c',1]], text:'#fff', accent:'#fff176', shapes:'circles' },
                { bg:[['#1565c0',0],['#1976d2',.5],['#2196f3',1]], text:'#fff', accent:'#ffeb3b', shapes:'circles' },
                { bg:[['#0288d1',0],['#039be5',.5],['#03a9f4',1]], text:'#fff', accent:'#ffd54f', shapes:'dots' },
                { bg:[['#00acc1',0],['#00bcd4',.5],['#26c6da',1]], text:'#fff', accent:'#fff59d', shapes:'circles' },
                { bg:[['#00897b',0],['#009688',.5],['#26a69a',1]], text:'#fff', accent:'#ffe082', shapes:'circles' },
                { bg:[['#43a047',0],['#4caf50',.5],['#66bb6a',1]], text:'#fff', accent:'#fff9c4', shapes:'dots' },
                { bg:[['#7cb342',0],['#8bc34a',.5],['#9ccc65',1]], text:'#fff', accent:'#ffeb3b', shapes:'circles' },
                { bg:[['#c0ca33',0],['#cddc39',.5],['#d4e157',1]], text:'#263238', accent:'#fbc02d', shapes:'circles' },
                { bg:[['#f9a825',0],['#fbc02d',.5],['#fdd835',1]], text:'#263238', accent:'#ff6f00', shapes:'dots' },
                { bg:[['#fb8c00',0],['#ff9800',.5],['#ffa726',1]], text:'#fff', accent:'#fff9c4', shapes:'circles' },
                { bg:[['#f4511e',0],['#ff5722',.5],['#ff6f43',1]], text:'#fff', accent:'#ffe082', shapes:'circles' },
                { bg:[['#e53935',0],['#f44336',.5],['#ef5350',1]], text:'#fff', accent:'#fff59d', shapes:'dots' },
                { bg:[['#d81b60',0],['#e91e63',.5],['#ec407a',1]], text:'#fff', accent:'#fff176', shapes:'circles' },
                { bg:[['#8e24aa',0],['#9c27b0',.5],['#ab47bc',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#5e35b1',0],['#673ab7',.5],['#7e57c2',1]], text:'#fff', accent:'#d1c4e9', shapes:'dots' },
                { bg:[['#3949ab',0],['#3f51b5',.5],['#5c6bc0',1]], text:'#fff', accent:'#c5cae9', shapes:'circles' },
                { bg:[['#1e88e5',0],['#2196f3',.5],['#42a5f5',1]], text:'#fff', accent:'#bbdefb', shapes:'circles' },
                { bg:[['#039be5',0],['#03a9f4',.5],['#29b6f6',1]], text:'#fff', accent:'#b3e5fc', shapes:'dots' },
                { bg:[['#00acc1',0],['#00bcd4',.5],['#26c6da',1]], text:'#fff', accent:'#b2ebf2', shapes:'circles' },
                { bg:[['#00897b',0],['#009688',.5],['#26a69a',1]], text:'#fff', accent:'#b2dfdb', shapes:'circles' },
                { bg:[['#43a047',0],['#4caf50',.5],['#66bb6a',1]], text:'#fff', accent:'#c8e6c9', shapes:'dots' },
                { bg:[['#689f38',0],['#7cb342',.5],['#8bc34a',1]], text:'#fff', accent:'#dcedc8', shapes:'circles' },
                { bg:[['#9e9d24',0],['#afb42b',.5],['#c0ca33',1]], text:'#fff', accent:'#f0f4c3', shapes:'circles' },
                { bg:[['#f57f17',0],['#f9a825',.5],['#fbc02d',1]], text:'#263238', accent:'#fff59d', shapes:'dots' },
                { bg:[['#ef6c00',0],['#f57c00',.5],['#fb8c00',1]], text:'#fff', accent:'#ffe0b2', shapes:'circles' },
                { bg:[['#d84315',0],['#e64a19',.5],['#f4511e',1]], text:'#fff', accent:'#ffccbc', shapes:'circles' },
                { bg:[['#c62828',0],['#d32f2f',.5],['#e53935',1]], text:'#fff', accent:'#ffcdd2', shapes:'dots' },
                { bg:[['#ad1457',0],['#c2185b',.5],['#d81b60',1]], text:'#fff', accent:'#f8bbd0', shapes:'circles' },
                { bg:[['#6a1b9a',0],['#7b1fa2',.5],['#8e24aa',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#4527a0',0],['#512da8',.5],['#5e35b1',1]], text:'#fff', accent:'#d1c4e9', shapes:'dots' },
                { bg:[['#283593',0],['#303f9f',.5],['#3949ab',1]], text:'#fff', accent:'#c5cae9', shapes:'circles' },
                { bg:[['#1565c0',0],['#1976d2',.5],['#1e88e5',1]], text:'#fff', accent:'#bbdefb', shapes:'circles' },
                { bg:[['#0277bd',0],['#0288d1',.5],['#039be5',1]], text:'#fff', accent:'#b3e5fc', shapes:'dots' },
                { bg:[['#00838f',0],['#0097a7',.5],['#00acc1',1]], text:'#fff', accent:'#b2ebf2', shapes:'circles' },
                { bg:[['#00695c',0],['#00796b',.5],['#00897b',1]], text:'#fff', accent:'#b2dfdb', shapes:'circles' },
                { bg:[['#2e7d32',0],['#388e3c',.5],['#43a047',1]], text:'#fff', accent:'#c8e6c9', shapes:'dots' },
                { bg:[['#558b2f',0],['#689f38',.5],['#7cb342',1]], text:'#fff', accent:'#dcedc8', shapes:'circles' },
                { bg:[['#827717',0],['#9e9d24',.5],['#afb42b',1]], text:'#fff', accent:'#f0f4c3', shapes:'circles' },
                { bg:[['#f57f17',0],['#f9a825',.5],['#fdd835',1]], text:'#1a237e', accent:'#fff176', shapes:'dots' },
                { bg:[['#ef6c00',0],['#f57c00',.5],['#ff8f00',1]], text:'#fff', accent:'#ffe082', shapes:'circles' },
                { bg:[['#d84315',0],['#e64a19',.5],['#ff5722',1]], text:'#fff', accent:'#ffab91', shapes:'circles' },
                { bg:[['#c62828',0],['#d32f2f',.5],['#f44336',1]], text:'#fff', accent:'#ef9a9a', shapes:'dots' }
            ],
            photo_realistic: [
                { bg:[['#1c2b3a',0],['#2d3f52',.5],['#3c5068',1]], text:'#e3f2fd', accent:'#80deea', shapes:'dots' },
                { bg:[['#2c3e50',0],['#34495e',.5],['#415b76',1]], text:'#ecf0f1', accent:'#81c784', shapes:'dots' },
                { bg:[['#212f3c',0],['#2e4053',.5],['#3b5168',1]], text:'#e8eaf6', accent:'#7986cb', shapes:'dots' },
                { bg:[['#1a252f',0],['#283747',.5],['#36495e',1]], text:'#f0f4c3', accent:'#aed581', shapes:'dots' },
                { bg:[['#263238',0],['#37474f',.5],['#455a64',1]], text:'#fff8e1', accent:'#ffd54f', shapes:'dots' },
                { bg:[['#1e272e',0],['#2f3640',.5],['#404b52',1]], text:'#fff3e0', accent:'#ffb74d', shapes:'dots' },
                { bg:[['#1b1f23',0],['#2c3138',.5],['#3d434c',1]], text:'#fce4ec', accent:'#f06292', shapes:'dots' },
                { bg:[['#1a1d22',0],['#2b2e33',.5],['#3c3f45',1]], text:'#f3e5f5', accent:'#ba68c8', shapes:'dots' },
                { bg:[['#191c20',0],['#2a2d31',.5],['#3b3e43',1]], text:'#e1f5fe', accent:'#4fc3f7', shapes:'dots' },
                { bg:[['#1c1f23',0],['#2d3035',.5],['#3e4147',1]], text:'#e0f2f1', accent:'#4db6ac', shapes:'dots' },
                { bg:[['#1d2025',0],['#2e3136',.5],['#3f4248',1]], text:'#e8f5e9', accent:'#66bb6a', shapes:'dots' },
                { bg:[['#1e2127',0],['#2f3238',.5],['#40434a',1]], text:'#f1f8e9', accent:'#9ccc65', shapes:'dots' },
                { bg:[['#1f2229',0],['#30333a',.5],['#41444c',1]], text:'#f9fbe7', accent:'#d4e157', shapes:'dots' },
                { bg:[['#20232b',0],['#31343c',.5],['#42454e',1]], text:'#fffde7', accent:'#ffee58', shapes:'dots' },
                { bg:[['#21242d',0],['#32353e',.5],['#43464f',1]], text:'#fff8e1', accent:'#ffca28', shapes:'dots' },
                { bg:[['#22252f',0],['#333641',.5],['#444751',1]], text:'#fff3e0', accent:'#ffa726', shapes:'dots' },
                { bg:[['#232631',0],['#343743',.5],['#454853',1]], text:'#fbe9e7', accent:'#ff7043', shapes:'dots' },
                { bg:[['#242733',0],['#353845',.5],['#464955',1]], text:'#ffebee', accent:'#ef5350', shapes:'dots' },
                { bg:[['#252835',0],['#363947',.5],['#474a57',1]], text:'#fce4ec', accent:'#ec407a', shapes:'dots' },
                { bg:[['#262937',0],['#373a49',.5],['#484b59',1]], text:'#f3e5f5', accent:'#ab47bc', shapes:'dots' },
                { bg:[['#272a39',0],['#383b4b',.5],['#494c5b',1]], text:'#ede7f6', accent:'#7e57c2', shapes:'dots' },
                { bg:[['#282b3b',0],['#393c4d',.5],['#4a4d5d',1]], text:'#e8eaf6', accent:'#5c6bc0', shapes:'dots' },
                { bg:[['#292c3d',0],['#3a3d4f',.5],['#4b4e5f',1]], text:'#e3f2fd', accent:'#42a5f5', shapes:'dots' },
                { bg:[['#2a2d3f',0],['#3b3e51',.5],['#4c4f61',1]], text:'#e1f5fe', accent:'#29b6f6', shapes:'dots' },
                { bg:[['#2b2e41',0],['#3c3f53',.5],['#4d5063',1]], text:'#e0f7fa', accent:'#26c6da', shapes:'dots' },
                { bg:[['#2c2f43',0],['#3d4055',.5],['#4e5165',1]], text:'#e0f2f1', accent:'#26a69a', shapes:'dots' },
                { bg:[['#2d3045',0],['#3e4157',.5],['#4f5267',1]], text:'#e8f5e9', accent:'#66bb6a', shapes:'dots' },
                { bg:[['#2e3147',0],['#3f4259',.5],['#505369',1]], text:'#f1f8e9', accent:'#9ccc65', shapes:'dots' },
                { bg:[['#2f3249',0],['#40435b',.5],['#51546b',1]], text:'#f9fbe7', accent:'#d4e157', shapes:'dots' },
                { bg:[['#30334b',0],['#41445d',.5],['#52556d',1]], text:'#fffde7', accent:'#ffee58', shapes:'dots' },
                { bg:[['#31344d',0],['#42455f',.5],['#53566f',1]], text:'#fff8e1', accent:'#ffca28', shapes:'dots' },
                { bg:[['#32354f',0],['#434661',.5],['#545771',1]], text:'#fff3e0', accent:'#ffa726', shapes:'dots' },
                { bg:[['#333651',0],['#444763',.5],['#555873',1]], text:'#fbe9e7', accent:'#ff7043', shapes:'dots' },
                { bg:[['#343753',0],['#454865',.5],['#565975',1]], text:'#ffebee', accent:'#ef5350', shapes:'dots' },
                { bg:[['#353855',0],['#464967',.5],['#575a77',1]], text:'#fce4ec', accent:'#ec407a', shapes:'dots' },
                { bg:[['#363957',0],['#474a69',.5],['#585b79',1]], text:'#f3e5f5', accent:'#ab47bc', shapes:'dots' },
                { bg:[['#373a59',0],['#484b6b',.5],['#595c7b',1]], text:'#ede7f6', accent:'#7e57c2', shapes:'dots' },
                { bg:[['#383b5b',0],['#494c6d',.5],['#5a5d7d',1]], text:'#e8eaf6', accent:'#5c6bc0', shapes:'dots' },
                { bg:[['#393c5d',0],['#4a4d6f',.5],['#5b5e7f',1]], text:'#e3f2fd', accent:'#42a5f5', shapes:'dots' },
                { bg:[['#3a3d5f',0],['#4b4e71',.5],['#5c5f81',1]], text:'#e1f5fe', accent:'#29b6f6', shapes:'dots' },
                { bg:[['#3b3e61',0],['#4c4f73',.5],['#5d6083',1]], text:'#e0f7fa', accent:'#26c6da', shapes:'dots' },
                { bg:[['#3c3f63',0],['#4d5075',.5],['#5e6185',1]], text:'#e0f2f1', accent:'#26a69a', shapes:'dots' }
            ],
            illustration: [
                { bg:[['#880e4f',0],['#6a1b9a',.5],['#311b92',1]], text:'#fff', accent:'#f8bbd0', shapes:'hexs' },
                { bg:[['#c2185b',0],['#7b1fa2',.5],['#4527a0',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' },
                { bg:[['#d81b60',0],['#8e24aa',.5],['#5e35b1',1]], text:'#fff', accent:'#ce93d8', shapes:'circles' },
                { bg:[['#e91e63',0],['#9c27b0',.5],['#673ab7',1]], text:'#fff', accent:'#ba68c8', shapes:'hexs' },
                { bg:[['#f06292',0],['#ab47bc',.5],['#7e57c2',1]], text:'#fff', accent:'#d1c4e9', shapes:'circles' },
                { bg:[['#ec407a',0],['#ba68c8',.5],['#9575cd',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' },
                { bg:[['#ad1457',0],['#6a1b9a',.5],['#4527a0',1]], text:'#fff', accent:'#f48fb1', shapes:'circles' },
                { bg:[['#c2185b',0],['#8e24aa',.5],['#5e35b1',1]], text:'#fff', accent:'#ce93d8', shapes:'hexs' },
                { bg:[['#d81b60',0],['#9c27b0',.5],['#673ab7',1]], text:'#fff', accent:'#d1c4e9', shapes:'circles' },
                { bg:[['#e91e63',0],['#ab47bc',.5],['#7e57c2',1]], text:'#fff', accent:'#ba68c8', shapes:'hexs' },
                { bg:[['#f06292',0],['#ba68c8',.5],['#9575cd',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#ec407a',0],['#ce93d8',.5],['#b39ddb',1]], text:'#fff', accent:'#f48fb1', shapes:'hexs' },
                { bg:[['#880e4f',0],['#4a148c',.5],['#311b92',1]], text:'#fff', accent:'#f8bbd0', shapes:'circles' },
                { bg:[['#ad1457',0],['#6a1b9a',.5],['#512da8',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' },
                { bg:[['#c2185b',0],['#7b1fa2',.5],['#5e35b1',1]], text:'#fff', accent:'#ce93d8', shapes:'circles' },
                { bg:[['#d81b60',0],['#8e24aa',.5],['#673ab7',1]], text:'#fff', accent:'#ba68c8', shapes:'hexs' },
                { bg:[['#e91e63',0],['#9c27b0',.5],['#7e57c2',1]], text:'#fff', accent:'#d1c4e9', shapes:'circles' },
                { bg:[['#f06292',0],['#ab47bc',.5],['#9575cd',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' },
                { bg:[['#ec407a',0],['#ba68c8',.5],['#b39ddb',1]], text:'#fff', accent:'#f48fb1', shapes:'circles' },
                { bg:[['#880e4f',0],['#6a1b9a',.5],['#4527a0',1]], text:'#fff', accent:'#f8bbd0', shapes:'hexs' },
                { bg:[['#ad1457',0],['#7b1fa2',.5],['#512da8',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#c2185b',0],['#8e24aa',.5],['#5e35b1',1]], text:'#fff', accent:'#ce93d8', shapes:'hexs' },
                { bg:[['#d81b60',0],['#9c27b0',.5],['#673ab7',1]], text:'#fff', accent:'#ba68c8', shapes:'circles' },
                { bg:[['#e91e63',0],['#ab47bc',.5],['#7e57c2',1]], text:'#fff', accent:'#d1c4e9', shapes:'hexs' },
                { bg:[['#f06292',0],['#ba68c8',.5],['#9575cd',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#ec407a',0],['#ce93d8',.5],['#b39ddb',1]], text:'#fff', accent:'#f48fb1', shapes:'hexs' },
                { bg:[['#880e4f',0],['#4a148c',.5],['#311b92',1]], text:'#fff', accent:'#f8bbd0', shapes:'circles' },
                { bg:[['#ad1457',0],['#6a1b9a',.5],['#4527a0',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' },
                { bg:[['#c2185b',0],['#7b1fa2',.5],['#512da8',1]], text:'#fff', accent:'#ce93d8', shapes:'circles' },
                { bg:[['#d81b60',0],['#8e24aa',.5],['#5e35b1',1]], text:'#fff', accent:'#ba68c8', shapes:'hexs' },
                { bg:[['#e91e63',0],['#9c27b0',.5],['#673ab7',1]], text:'#fff', accent:'#d1c4e9', shapes:'circles' },
                { bg:[['#f06292',0],['#ab47bc',.5],['#7e57c2',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' },
                { bg:[['#ec407a',0],['#ba68c8',.5],['#9575cd',1]], text:'#fff', accent:'#f48fb1', shapes:'circles' },
                { bg:[['#880e4f',0],['#6a1b9a',.5],['#4527a0',1]], text:'#fff', accent:'#f8bbd0', shapes:'hexs' },
                { bg:[['#ad1457',0],['#7b1fa2',.5],['#512da8',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#c2185b',0],['#8e24aa',.5],['#5e35b1',1]], text:'#fff', accent:'#ce93d8', shapes:'hexs' },
                { bg:[['#d81b60',0],['#9c27b0',.5],['#673ab7',1]], text:'#fff', accent:'#ba68c8', shapes:'circles' },
                { bg:[['#e91e63',0],['#ab47bc',.5],['#7e57c2',1]], text:'#fff', accent:'#d1c4e9', shapes:'hexs' },
                { bg:[['#f06292',0],['#ba68c8',.5],['#9575cd',1]], text:'#fff', accent:'#e1bee7', shapes:'circles' },
                { bg:[['#ec407a',0],['#ce93d8',.5],['#b39ddb',1]], text:'#fff', accent:'#f48fb1', shapes:'hexs' },
                { bg:[['#880e4f',0],['#4a148c',.5],['#311b92',1]], text:'#fff', accent:'#f8bbd0', shapes:'circles' },
                { bg:[['#ad1457',0],['#6a1b9a',.5],['#4527a0',1]], text:'#fff', accent:'#e1bee7', shapes:'hexs' }
            ],
            typography: [
                { bg:[['#0d0d0d',0],['#1a1a1a',.5],['#262626',1]], text:'#f5f5f5', accent:'#e0e0e0', shapes:'lines' },
                { bg:[['#1a1a1a',0],['#262626',.5],['#333333',1]], text:'#ffffff', accent:'#bdbdbd', shapes:'lines' },
                { bg:[['#212121',0],['#303030',.5],['#3d3d3d',1]], text:'#fafafa', accent:'#e0e0e0', shapes:'lines' },
                { bg:[['#0a0a0a',0],['#141414',.5],['#1e1e1e',1]], text:'#f0f0f0', accent:'#9e9e9e', shapes:'lines' },
                { bg:[['#f5f5f5',0],['#eeeeee',.5],['#e0e0e0',1]], text:'#212121', accent:'#424242', shapes:'lines' },
                { bg:[['#ffffff',0],['#f5f5f5',.5],['#eeeeee',1]], text:'#000000', accent:'#212121', shapes:'lines' },
                { bg:[['#fafafa',0],['#f0f0f0',.5],['#e8e8e8',1]], text:'#1a1a1a', accent:'#333333', shapes:'lines' },
                { bg:[['#1c1c1e',0],['#2c2c2e',.5],['#3a3a3c',1]], text:'#f2f2f7', accent:'#aeaeb2', shapes:'luxury' },
                { bg:[['#000000',0],['#0d0d0d',.5],['#1a1a1a',1]], text:'#ff3b30', accent:'#ff6961', shapes:'luxury' },
                { bg:[['#000000',0],['#0d0d0d',.5],['#1a1a1a',1]], text:'#0a84ff', accent:'#5ac8fa', shapes:'luxury' },
                { bg:[['#000000',0],['#0d0d0d',.5],['#1a1a1a',1]], text:'#30d158', accent:'#a2fd6e', shapes:'luxury' },
                { bg:[['#1a0030',0],['#2d004b',.5],['#3f0066',1]], text:'#e040fb', accent:'#ce93d8', shapes:'luxury' },
                { bg:[['#001a33',0],['#002b52',.5],['#003d70',1]], text:'#40c4ff', accent:'#b3e5fc', shapes:'luxury' },
                { bg:[['#1a2e00',0],['#2a4a00',.5],['#3a6600',1]], text:'#b2ff59', accent:'#ccff90', shapes:'luxury' },
                { bg:[['#330000',0],['#4d0000',.5],['#660000',1]], text:'#ff8a80', accent:'#ffcdd2', shapes:'luxury' },
                { bg:[['#001a00',0],['#002d00',.5],['#004000',1]], text:'#69f0ae', accent:'#b9f6ca', shapes:'luxury' },
                { bg:[['#f5f0e8',0],['#ede5d0',.5],['#e0d4b4',1]], text:'#2c1810', accent:'#5d4037', shapes:'lines' },
                { bg:[['#e8e8e8',0],['#d5d5d5',.5],['#c2c2c2',1]], text:'#1a1a1a', accent:'#424242', shapes:'lines' },
                { bg:[['#1a1a2e',0],['#16213e',.5],['#0f3460',1]], text:'#e94560', accent:'#f5a7a7', shapes:'luxury' },
                { bg:[['#2d1b69',0],['#11998e',.4],['#38ef7d',1]], text:'#ffffff', accent:'#e0e0e0', shapes:'luxury' },
                { bg:[['#0a0a0a',0],['#1a1a1a',.5],['#0a0a0a',1]], text:'#ffd700', accent:'#ffec6e', shapes:'luxury' },
                { bg:[['#0a0a0a',0],['#1a1a1a',.5],['#0a0a0a',1]], text:'#c0c0c0', accent:'#e8e8e8', shapes:'luxury' },
                { bg:[['#1a0a00',0],['#2d1500',.5],['#3f1f00',1]], text:'#ff9800', accent:'#ffcc80', shapes:'luxury' },
                { bg:[['#00001a',0],['#00002d',.5],['#000040',1]], text:'#64b5f6', accent:'#bbdefb', shapes:'luxury' },
                { bg:[['#f0f0f0',0],['#e0e0e0',.5],['#d0d0d0',1]], text:'#212121', accent:'#616161', shapes:'lines' },
                { bg:[['#2e2e2e',0],['#3e3e3e',.5],['#4e4e4e',1]], text:'#f5f5f5', accent:'#bdbdbd', shapes:'lines' },
                { bg:[['#1c1c1c',0],['#2a2a2a',.5],['#383838',1]], text:'#e0e0e0', accent:'#9e9e9e', shapes:'luxury' },
                { bg:[['#f8f8f8',0],['#f0f0f0',.5],['#e8e8e8',1]], text:'#1a1a1a', accent:'#333333', shapes:'lines' },
                { bg:[['#0f0f0f',0],['#1d1d1d',.5],['#2b2b2b',1]], text:'#f0f0f0', accent:'#757575', shapes:'luxury' },
                { bg:[['#fefefe',0],['#f2f2f2',.5],['#e5e5e5',1]], text:'#111111', accent:'#444444', shapes:'lines' },
                { bg:[['#111111',0],['#222222',.5],['#333333',1]], text:'#ffffff', accent:'#cccccc', shapes:'luxury' },
                { bg:[['#1a1a1a',0],['#2d2d2d',.5],['#404040',1]], text:'#ffffff', accent:'#888888', shapes:'luxury' },
                { bg:[['#f5f5f5',0],['#e8e8e8',.5],['#dadada',1]], text:'#000000', accent:'#555555', shapes:'lines' },
                { bg:[['#0c0c0c',0],['#1c1c1c',.5],['#2c2c2c',1]], text:'#e0e0e0', accent:'#616161', shapes:'luxury' },
                { bg:[['#1f1f1f',0],['#2f2f2f',.5],['#3f3f3f',1]], text:'#fafafa', accent:'#9e9e9e', shapes:'luxury' },
                { bg:[['#f0f0f0',0],['#e5e5e5',.5],['#d8d8d8',1]], text:'#212121', accent:'#4a4a4a', shapes:'lines' },
                { bg:[['#141414',0],['#242424',.5],['#343434',1]], text:'#f5f5f5', accent:'#9e9e9e', shapes:'luxury' },
                { bg:[['#fdfdfd',0],['#f5f5f5',.5],['#ececec',1]], text:'#1a1a1a', accent:'#3d3d3d', shapes:'lines' },
                { bg:[['#171717',0],['#272727',.5],['#373737',1]], text:'#ffffff', accent:'#ababab', shapes:'luxury' },
                { bg:[['#f8f8f8',0],['#efefef',.5],['#e6e6e6',1]], text:'#0d0d0d', accent:'#404040', shapes:'lines' },
                { bg:[['#0e0e0e',0],['#1e1e1e',.5],['#2e2e2e',1]], text:'#eeeeee', accent:'#757575', shapes:'luxury' },
                { bg:[['#fcfcfc',0],['#f3f3f3',.5],['#eaeaea',1]], text:'#111111', accent:'#484848', shapes:'lines' }
            ],
            bright_gradient: [
                { bg:[['#e65100',0],['#ef6c00',.4],['#f9a825',1]], text:'#fff', accent:'#fff9c4', shapes:'waves' },
                { bg:[['#d84315',0],['#e64a19',.4],['#fb8c00',1]], text:'#fff', accent:'#ffe082', shapes:'waves' },
                { bg:[['#bf360c',0],['#d84315',.4],['#f57c00',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#f9a825',.4],['#fdd835',1]], text:'#263238', accent:'#ff6f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#f57c00',.4],['#ff9800',1]], text:'#fff', accent:'#fff59d', shapes:'waves' },
                { bg:[['#e65100',0],['#ef6c00',.4],['#fb8c00',1]], text:'#fff', accent:'#ffecb3', shapes:'waves' },
                { bg:[['#d84315',0],['#e64a19',.4],['#f4511e',1]], text:'#fff', accent:'#ffe0b2', shapes:'waves' },
                { bg:[['#bf360c',0],['#d84315',.4],['#e64a19',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#f9a825',.4],['#fbc02d',1]], text:'#263238', accent:'#ff8f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#f57c00',.4],['#ff8f00',1]], text:'#fff', accent:'#fff176', shapes:'waves' },
                { bg:[['#e65100',0],['#ef6c00',.4],['#f57c00',1]], text:'#fff', accent:'#fff9c4', shapes:'waves' },
                { bg:[['#d84315',0],['#e64a19',.4],['#ff5722',1]], text:'#fff', accent:'#ffe082', shapes:'waves' },
                { bg:[['#bf360c',0],['#d84315',.4],['#f4511e',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#f9a825',.4],['#fdd835',1]], text:'#1a237e', accent:'#ff6f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#f57c00',.4],['#ffa726',1]], text:'#fff', accent:'#fff59d', shapes:'waves' },
                { bg:[['#e65100',0],['#ef6c00',.4],['#ff9800',1]], text:'#fff', accent:'#ffecb3', shapes:'waves' },
                { bg:[['#d84315',0],['#e64a19',.4],['#f57c00',1]], text:'#fff', accent:'#ffe0b2', shapes:'waves' },
                { bg:[['#bf360c',0],['#d84315',.4],['#ef6c00',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#f9a825',.4],['#fbc02d',1]], text:'#263238', accent:'#ff8f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#f57c00',.4],['#ff8f00',1]], text:'#fff', accent:'#fff176', shapes:'waves' },
                { bg:[['#e65100',0],['#ff6f00',.4],['#ff9800',1]], text:'#fff', accent:'#fff9c4', shapes:'waves' },
                { bg:[['#d84315',0],['#f4511e',.4],['#ff7043',1]], text:'#fff', accent:'#ffe082', shapes:'waves' },
                { bg:[['#bf360c',0],['#e64a19',.4],['#ff5722',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#fbc02d',.4],['#fdd835',1]], text:'#263238', accent:'#ff6f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#ff8f00',.4],['#ffa726',1]], text:'#fff', accent:'#fff59d', shapes:'waves' },
                { bg:[['#e65100',0],['#f57c00',.4],['#ff9800',1]], text:'#fff', accent:'#ffecb3', shapes:'waves' },
                { bg:[['#d84315',0],['#ef6c00',.4],['#f57c00',1]], text:'#fff', accent:'#ffe0b2', shapes:'waves' },
                { bg:[['#bf360c',0],['#e65100',.4],['#ef6c00',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#f9a825',.4],['#fdd835',1]], text:'#1a237e', accent:'#ff8f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#f9a825',.4],['#ff8f00',1]], text:'#fff', accent:'#fff176', shapes:'waves' },
                { bg:[['#e65100',0],['#f9a825',.4],['#ff9800',1]], text:'#fff', accent:'#fff9c4', shapes:'waves' },
                { bg:[['#d84315',0],['#fb8c00',.4],['#ff7043',1]], text:'#fff', accent:'#ffe082', shapes:'waves' },
                { bg:[['#bf360c',0],['#f57c00',.4],['#ff5722',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#fdd835',.4],['#ffeb3b',1]], text:'#263238', accent:'#ff6f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#ffa726',.4],['#ffb74d',1]], text:'#fff', accent:'#fff59d', shapes:'waves' },
                { bg:[['#e65100',0],['#ff9800',.4],['#ffa726',1]], text:'#fff', accent:'#ffecb3', shapes:'waves' },
                { bg:[['#d84315',0],['#f57c00',.4],['#ff8f00',1]], text:'#fff', accent:'#ffe0b2', shapes:'waves' },
                { bg:[['#bf360c',0],['#ef6c00',.4],['#f9a825',1]], text:'#fff', accent:'#ffd54f', shapes:'waves' },
                { bg:[['#f57f17',0],['#fdd835',.4],['#ffee58',1]], text:'#1a237e', accent:'#ff8f00', shapes:'waves' },
                { bg:[['#ef6c00',0],['#ffb74d',.4],['#ffca28',1]], text:'#fff', accent:'#fff176', shapes:'waves' },
                { bg:[['#e65100',0],['#ffa726',.4],['#ffb74d',1]], text:'#fff', accent:'#fff9c4', shapes:'waves' },
                { bg:[['#d84315',0],['#ff8f00',.4],['#ffa726',1]], text:'#fff', accent:'#ffe082', shapes:'waves' }
            ],
            branding: [
                { bg:[['#1b5e20',0],['#2e7d32',.5],['#388e3c',1]], text:'#fff', accent:'#dcedc8', shapes:'leaves' },
                { bg:[['#2e7d32',0],['#388e3c',.5],['#43a047',1]], text:'#fff', accent:'#c5e1a5', shapes:'leaves' },
                { bg:[['#388e3c',0],['#43a047',.5],['#4caf50',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#43a047',0],['#4caf50',.5],['#66bb6a',1]], text:'#fff', accent:'#9ccc65', shapes:'leaves' },
                { bg:[['#4caf50',0],['#66bb6a',.5],['#81c784',1]], text:'#fff', accent:'#8bc34a', shapes:'leaves' },
                { bg:[['#558b2f',0],['#689f38',.5],['#7cb342',1]], text:'#fff', accent:'#dcedc8', shapes:'leaves' },
                { bg:[['#689f38',0],['#7cb342',.5],['#8bc34a',1]], text:'#fff', accent:'#c5e1a5', shapes:'leaves' },
                { bg:[['#7cb342',0],['#8bc34a',.5],['#9ccc65',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#827717',0],['#9e9d24',.5],['#afb42b',1]], text:'#fff', accent:'#f0f4c3', shapes:'leaves' },
                { bg:[['#9e9d24',0],['#afb42b',.5],['#c0ca33',1]], text:'#263238', accent:'#dce775', shapes:'leaves' },
                { bg:[['#00695c',0],['#00796b',.5],['#00897b',1]], text:'#fff', accent:'#b2dfdb', shapes:'leaves' },
                { bg:[['#00796b',0],['#00897b',.5],['#009688',1]], text:'#fff', accent:'#a7ffeb', shapes:'leaves' },
                { bg:[['#00897b',0],['#009688',.5],['#26a69a',1]], text:'#fff', accent:'#80cbc4', shapes:'leaves' },
                { bg:[['#004d40',0],['#00695c',.5],['#00796b',1]], text:'#fff', accent:'#b2dfdb', shapes:'leaves' },
                { bg:[['#1b5e20',0],['#2e7d32',.5],['#43a047',1]], text:'#fff', accent:'#c8e6c9', shapes:'leaves' },
                { bg:[['#2e7d32',0],['#388e3c',.5],['#4caf50',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#388e3c',0],['#43a047',.5],['#66bb6a',1]], text:'#fff', accent:'#9ccc65', shapes:'leaves' },
                { bg:[['#43a047',0],['#4caf50',.5],['#81c784',1]], text:'#fff', accent:'#8bc34a', shapes:'leaves' },
                { bg:[['#4caf50',0],['#66bb6a',.5],['#a5d6a7',1]], text:'#fff', accent:'#7cb342', shapes:'leaves' },
                { bg:[['#558b2f',0],['#689f38',.5],['#8bc34a',1]], text:'#fff', accent:'#dcedc8', shapes:'leaves' },
                { bg:[['#689f38',0],['#7cb342',.5],['#9ccc65',1]], text:'#fff', accent:'#c5e1a5', shapes:'leaves' },
                { bg:[['#7cb342',0],['#8bc34a',.5],['#aed581',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#827717',0],['#9e9d24',.5],['#c0ca33',1]], text:'#fff', accent:'#f0f4c3', shapes:'leaves' },
                { bg:[['#9e9d24',0],['#afb42b',.5],['#cddc39',1]], text:'#263238', accent:'#dce775', shapes:'leaves' },
                { bg:[['#00695c',0],['#00796b',.5],['#009688',1]], text:'#fff', accent:'#b2dfdb', shapes:'leaves' },
                { bg:[['#00796b',0],['#00897b',.5],['#26a69a',1]], text:'#fff', accent:'#a7ffeb', shapes:'leaves' },
                { bg:[['#00897b',0],['#009688',.5],['#4db6ac',1]], text:'#fff', accent:'#80cbc4', shapes:'leaves' },
                { bg:[['#004d40',0],['#00695c',.5],['#00897b',1]], text:'#fff', accent:'#b2dfdb', shapes:'leaves' },
                { bg:[['#1b5e20',0],['#2e7d32',.5],['#4caf50',1]], text:'#fff', accent:'#c8e6c9', shapes:'leaves' },
                { bg:[['#2e7d32',0],['#388e3c',.5],['#66bb6a',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#388e3c',0],['#43a047',.5],['#81c784',1]], text:'#fff', accent:'#9ccc65', shapes:'leaves' },
                { bg:[['#43a047',0],['#4caf50',.5],['#a5d6a7',1]], text:'#fff', accent:'#8bc34a', shapes:'leaves' },
                { bg:[['#4caf50',0],['#66bb6a',.5],['#c8e6c9',1]], text:'#fff', accent:'#7cb342', shapes:'leaves' },
                { bg:[['#558b2f',0],['#689f38',.5],['#9ccc65',1]], text:'#fff', accent:'#dcedc8', shapes:'leaves' },
                { bg:[['#689f38',0],['#7cb342',.5],['#aed581',1]], text:'#fff', accent:'#c5e1a5', shapes:'leaves' },
                { bg:[['#7cb342',0],['#8bc34a',.5],['#c5e1a5',1]], text:'#fff', accent:'#aed581', shapes:'leaves' },
                { bg:[['#827717',0],['#9e9d24',.5],['#cddc39',1]], text:'#fff', accent:'#f0f4c3', shapes:'leaves' },
                { bg:[['#9e9d24',0],['#afb42b',.5],['#d4e157',1]], text:'#263238', accent:'#dce775', shapes:'leaves' },
                { bg:[['#00695c',0],['#00796b',.5],['#26a69a',1]], text:'#fff', accent:'#b2dfdb', shapes:'leaves' },
                { bg:[['#00796b',0],['#00897b',.5],['#4db6ac',1]], text:'#fff', accent:'#a7ffeb', shapes:'leaves' },
                { bg:[['#00897b',0],['#009688',.5],['#80cbc4',1]], text:'#fff', accent:'#80cbc4', shapes:'leaves' },
                { bg:[['#004d40',0],['#00695c',.5],['#009688',1]], text:'#fff', accent:'#b2dfdb', shapes:'leaves' }
            ]
        };

        // 스타일에 해당하는 배경 목록 가져오기
        var variants = styleVariants[style] || styleVariants.poster;

        // 무작위로 하나 선택 (매번 다른 배경 생성)
        var t = variants[Math.floor(Math.random() * variants.length)];

        // ① 배경
        var g = ctx.createLinearGradient(0,0,W,H);
        t.bg.forEach(function(s){ g.addColorStop(s[1],s[0]); });
        ctx.fillStyle = g; ctx.fillRect(0,0,W,H);

        // ② 장식 도형
        ctx.save(); ctx.globalAlpha = 0.18;
        switch(t.shapes){
            case 'rects':
                [[W*.75,H*.5,W*.55,H*1.4,-18],[W*.90,H*.5,W*.22,H*1.4,-18]].forEach(function(r){
                    ctx.save(); ctx.translate(r[0],r[1]); ctx.rotate(r[4]*Math.PI/180);
                    ctx.fillStyle=t.accent; ctx.fillRect(-r[2]/2,-r[3]/2,r[2],r[3]); ctx.restore();
                }); break;
            case 'circles':
                [[W*.87,H*.13,190],[W*.08,H*.87,130],[W*.80,H*.82,80]].forEach(function(c){
                    ctx.beginPath(); ctx.arc(c[0],c[1],c[2],0,Math.PI*2);
                    ctx.fillStyle=t.accent; ctx.fill();
                }); break;
            case 'dots':
                ctx.fillStyle=t.accent;
                for(var di=0;di<10;di++) for(var dj=0;dj<6;dj++) if((di+dj)%3===0){
                    ctx.beginPath(); ctx.arc(di*130+30,dj*110+20,7,0,Math.PI*2); ctx.fill();
                } break;
            case 'lines':
                ctx.strokeStyle=t.accent; ctx.lineWidth=3;
                [.25,.5,.75].forEach(function(y){
                    ctx.beginPath(); ctx.moveTo(0,H*y); ctx.lineTo(W,H*y); ctx.stroke();
                }); break;
            case 'hexs':
                ctx.fillStyle=t.accent;
                [[W*.90,H*.12,95],[W*.04,H*.78,65],[W*.93,H*.78,45]].forEach(function(h){
                    ctx.beginPath();
                    for(var i=0;i<6;i++){var a=Math.PI/3*i-Math.PI/6;
                        (i?ctx.lineTo:ctx.moveTo).call(ctx,h[0]+h[2]*Math.cos(a),h[1]+h[2]*Math.sin(a));}
                    ctx.closePath(); ctx.fill();
                }); break;
            case 'luxury':
                ctx.strokeStyle=t.accent; ctx.lineWidth=2; ctx.globalAlpha=.4;
                ctx.strokeRect(28,28,W-56,H-56); ctx.strokeRect(42,42,W-84,H-84); break;
            case 'waves':
                ctx.strokeStyle=t.accent; ctx.lineWidth=4;
                for(var wi=0;wi<5;wi++){ctx.beginPath();
                    for(var xi=0;xi<=W;xi+=6){var yi=H*(.12+wi*.18)+Math.sin(xi/65+wi)*40;
                        (xi?ctx.lineTo:ctx.moveTo).call(ctx,xi,yi);} ctx.stroke();
                } break;
            case 'leaves':
                ctx.fillStyle=t.accent;
                [[W*.92,H*.10,110,55,-35],[W*.04,H*.14,85,42,25],[W*.90,H*.88,75,38,50]].forEach(function(l){
                    ctx.save(); ctx.translate(l[0],l[1]); ctx.rotate(l[4]*Math.PI/180);
                    ctx.beginPath(); ctx.ellipse(0,0,l[2],l[3],0,0,Math.PI*2); ctx.fill(); ctx.restore();
                }); break;
        }
        ctx.restore();

        // ③ 좌측 강조 바 (minimal 제외)
        if(style !== 'minimal'){
            ctx.save(); ctx.fillStyle=t.accent; ctx.globalAlpha=.85;
            ctx.fillRect(0,0,8,H); ctx.restore();
        }

        // ④ 제목 텍스트만 (다른 텍스트 없음)
        var ff   = "'Malgun Gothic','Apple SD Gothic Neo','Nanum Gothic',sans-serif";
        var px   = 72;
        var xOff = style==='minimal' ? W*.06 : 44;
        var maxW = W - xOff*2 - 20;
        var lines;
        while(px >= 32){
            lines = cvWrap(ctx, topic, maxW, '700 '+px+'px '+ff);
            if(lines.length <= 3) break;
            px -= 8;
        }
        lines = lines.slice(0, 3);
        var lh = Math.round(px*1.28);
        var startY = H/2 - (lines.length*lh)/2;

        ctx.save();
        ctx.textBaseline = 'top';
        ctx.font = '700 '+px+'px '+ff;
        lines.forEach(function(ln, i){
            if(style !== 'minimal'){
                ctx.shadowColor='rgba(0,0,0,.7)'; ctx.shadowBlur=16;
                ctx.shadowOffsetX=2; ctx.shadowOffsetY=3;
            }
            ctx.fillStyle=t.text; ctx.globalAlpha=1;
            ctx.fillText(ln, xOff, startY + i*lh);
        });
        ctx.restore();

        // ⑤ 하단 바
        ctx.save();
        var bar=ctx.createLinearGradient(0,0,W,0);
        bar.addColorStop(0,t.accent); bar.addColorStop(.6,t.accent); bar.addColorStop(1,'transparent');
        ctx.fillStyle=bar; ctx.globalAlpha=style==='typography'?.8:.5;
        ctx.fillRect(0,H-8,W,8); ctx.restore();

        return cv.toDataURL('image/jpeg', 0.92);
    }

    function cvWrap(ctx, text, maxW, font) {
        ctx.font = font;
        var words = text.split(/\s+/).filter(Boolean);
        if(words.length===1 && text.length>8){
            var h=Math.ceil(text.length/2), a=text.slice(0,h), b=text.slice(h);
            if(ctx.measureText(a).width<=maxW && ctx.measureText(b).width<=maxW)
                return b ? [a,b] : [a];
        }
        var lines=[], cur='';
        words.forEach(function(w){
            var t=cur?cur+' '+w:w;
            if(ctx.measureText(t).width>maxW && cur){ lines.push(cur); cur=w; }
            else cur=t;
        });
        if(cur) lines.push(cur);
        return lines.length ? lines : [text];
    }

    function thumbMsg(msg, type) {
        var $el = $('#zorlinq32-ai-thumb-status');
        if (!msg) { $el.hide(); return; }
        var bg = type === 'success' ? '#e8f5e9' : type === 'error' ? '#ffebee' : '#fff3e0';
        var cl = type === 'success' ? '#1b5e20' : type === 'error' ? '#b71c1c' : '#e65100';
        $el.css({ display:'block', background: bg, color: cl,
                  border: '1px solid ' + cl, borderRadius:'6px',
                  padding:'8px 12px', fontSize:'12px', fontWeight:'600',
                  marginTop:'8px', lineHeight:'1.5' })
           .html(msg);
    }

    // 하위 호환
    function setThumbStatus(msg, type) { thumbMsg(msg, type); }

    /* ══════════════════════════════════════
       AI 콘텐츠 확장 — 선택 문장을 3~4문장으로 인라인 확장
    ══════════════════════════════════════ */
    function initContentExpander() {
        var selectedText     = '';   // 선택된 순수 텍스트
        var savedRange       = null; // DOM Range (Gutenberg용)
        var savedTinyBookmark= null; // TinyMCE 북마크
        var savedTinyEditor  = null; // TinyMCE 편집기 인스턴스
        var savedBlockInfo   = null; // Gutenberg 일반 블록(문단/제목 등) attribute 갱신용 정보
        var $expandBtn       = null;
        var isExpanding      = false; // AI 재작성 중 여부 (true이면 버튼 재생성 방지)

        // ── 선택 영역이 속한 Gutenberg 블록과, attribute 내 텍스트 오프셋을 찾음 ──
        // 클래식 편집기 블록(core/freeform)뿐 아니라 core/paragraph, core/heading,
        // core/list 등 React가 상태를 관리하는 일반 블록에서도 확장 결과가
        // 실제 블록 attribute에 반영되도록 하기 위함.
        function findGutenbergBlockForSelection(range) {
            try {
                if (typeof wp === 'undefined' || !wp.data) return null;
                var editorSelect = wp.data.select('core/block-editor');
                if (!editorSelect || !editorSelect.getBlocks) return null;

                var node = range.commonAncestorContainer;
                if (node.nodeType === 3) node = node.parentNode;
                var blockEl = node ? node.closest('[data-block]') : null;
                if (!blockEl) return null;

                var clientId = blockEl.getAttribute('data-block');
                if (!clientId) return null;

                var block = editorSelect.getBlock(clientId);
                if (!block) return null;

                var attrKey = null;
                if (typeof block.attributes.content === 'string') attrKey = 'content';
                if (!attrKey) return null;

                var attrHtml = block.attributes[attrKey] || '';
                var tmp = document.createElement('div');
                tmp.innerHTML = attrHtml;
                var plainText = tmp.textContent || tmp.innerText || '';

                var idx = plainText.indexOf(selectedText);
                if (idx === -1) return null;

                return {
                    clientId: clientId,
                    attrKey: attrKey,
                    attrHtml: attrHtml,
                    plainText: plainText,
                    startIdx: idx,
                    endIdx: idx + selectedText.length
                };
            } catch(e) {
                return null;
            }
        }

        // ── 하단 토스트 알림 ──
        function showExpandToast(msg, type) {
            $('#zorlinq32-ai-expand-toast').remove();
            var bg  = type === 'success' ? '#1b5e20' : (type === 'error' ? '#b71c1c' : '#1a237e');
            var ico = type === 'success' ? '✅' : (type === 'error' ? '❌' : 'ℹ️');
            var $t = $('<div id="zorlinq32-ai-expand-toast">' + ico + ' ' + msg + '</div>').css({
                position:'fixed', bottom:'32px', left:'50%', transform:'translateX(-50%)',
                background:bg, color:'#fff', padding:'12px 28px', borderRadius:'30px',
                fontSize:'14px', fontWeight:'700', boxShadow:'0 4px 20px rgba(0,0,0,.35)',
                zIndex:9999999, opacity:0, whiteSpace:'nowrap', pointerEvents:'none'
            });
            $('body').append($t);
            $t.animate({opacity:1}, 200);
            setTimeout(function() { $t.animate({opacity:0}, 400, function(){ $(this).remove(); }); }, 3000);
        }

        // ── 선택 영역 저장 (AJAX 전에 반드시 호출) ──
        function saveSelection() {
            savedRange        = null;
            savedTinyBookmark = null;
            savedTinyEditor   = null;
            savedBlockInfo    = null;

            // TinyMCE 선택 저장 (클래식 편집기 / core/freeform 블록 내부)
            if (typeof tinymce !== 'undefined') {
                var eds = tinymce.editors;
                for (var i = 0; i < eds.length; i++) {
                    var ed = eds[i];
                    if (ed && !ed.isHidden()) {
                        try {
                            var sel = ed.selection.getContent({format:'text'});
                            if (sel && sel.trim().length > 5) {
                                savedTinyEditor   = ed;
                                savedTinyBookmark = ed.selection.getBookmark(2, true);
                                return true;
                            }
                        } catch(e) {}
                    }
                }
            }

            // Gutenberg/DOM 선택 저장
            try {
                var domSel = window.getSelection();
                if (domSel && domSel.rangeCount > 0 && !domSel.isCollapsed) {
                    savedRange = domSel.getRangeAt(0).cloneRange();

                    // 일반 Gutenberg 블록(문단/제목/리스트 등)이면 블록 attribute
                    // 갱신 정보도 함께 저장 — React state와 동기화되도록 하기 위함.
                    savedBlockInfo = findGutenbergBlockForSelection(savedRange);
                    return true;
                }
            } catch(e) {}

            return false;
        }

        // ── 저장된 선택 영역에 텍스트 교체 ──
        function replaceWithSaved(expandedText) {

            // ① TinyMCE
            if (savedTinyEditor && savedTinyBookmark) {
                try {
                    savedTinyEditor.focus();
                    savedTinyEditor.selection.moveToBookmark(savedTinyBookmark);
                    savedTinyEditor.selection.setContent(expandedText);
                    savedTinyEditor.undoManager.add();
                    return true;
                } catch(e) {
                    console.warn('TinyMCE 교체 실패:', e);
                }
            }

            // ② Gutenberg 일반 블록(문단/제목/리스트 등) — 블록 attribute를 직접 갱신
            //    React가 상태를 관리하므로 DOM만 바꾸면 리렌더링 시 되돌아가거나
            //    저장 시 반영되지 않음. wp.data dispatch로 실제 state를 갱신해야
            //    클래식 블록이 아닌 일반 블록 편집기에서도 정상 동작함.
            if (savedBlockInfo) {
                try {
                    var editorDispatch = wp.data.dispatch('core/block-editor');
                    var editorSelect2  = wp.data.select('core/block-editor');
                    if (editorDispatch && editorSelect2) {
                        var curBlock = editorSelect2.getBlock(savedBlockInfo.clientId);
                        if (curBlock) {
                            var curHtml = curBlock.attributes[savedBlockInfo.attrKey] || '';
                            // 저장 시점 이후 attribute가 바뀌었을 수 있으므로,
                            // 현재 값 기준으로 선택 텍스트 위치를 다시 탐색
                            var tmp2 = document.createElement('div');
                            tmp2.innerHTML = curHtml;
                            var curPlain = tmp2.textContent || tmp2.innerText || '';
                            var idx2 = curPlain.indexOf(selectedText);

                            if (idx2 !== -1) {
                                // 순수 텍스트 기준으로 교체 후, 남은 인라인 마크업은
                                // 최대한 보존하기 위해 원본 HTML에서 텍스트 노드만 치환
                                var newHtml = replaceTextInHtml(curHtml, selectedText, expandedText);
                                if (newHtml !== null) {
                                    var attrs = {};
                                    attrs[savedBlockInfo.attrKey] = newHtml;
                                    editorDispatch.updateBlockAttributes(savedBlockInfo.clientId, attrs);
                                    return true;
                                }
                            }
                        }
                    }
                } catch(e) {
                    console.warn('Gutenberg 블록 attribute 갱신 실패:', e);
                }
            }

            // ③ Gutenberg contenteditable (저장된 Range 사용, DOM 폴백)
            if (savedRange) {
                try {
                    // 저장된 Range를 현재 selection에 복원
                    var domSel = window.getSelection();
                    domSel.removeAllRanges();
                    domSel.addRange(savedRange);

                    // document.execCommand는 deprecated지만 contenteditable에서 가장 안정적
                    if (document.execCommand('insertText', false, expandedText)) {
                        return true;
                    }

                    // execCommand 실패 시 Range API 직접 사용
                    savedRange.deleteContents();
                    var textNode = document.createTextNode(expandedText);
                    savedRange.insertNode(textNode);
                    savedRange.setStartAfter(textNode);
                    savedRange.setEndAfter(textNode);
                    domSel.removeAllRanges();
                    domSel.addRange(savedRange);

                    // React state 동기화 트리거 (Gutenberg)
                    textNode.parentNode && textNode.parentNode.dispatchEvent(new Event('input', {bubbles:true}));
                    return true;
                } catch(e) {
                    console.warn('Range 교체 실패:', e);
                }
            }

            // ④ Textarea 폴백
            try {
                var $ta = $('#content');
                if ($ta.length) {
                    var v = $ta.val();
                    var foundIdx = v.indexOf(selectedText);
                    if (foundIdx !== -1) {
                        $ta.val(v.substring(0, foundIdx) + expandedText + v.substring(foundIdx + selectedText.length));
                        return true;
                    }
                }
            } catch(e) {}

            return false;
        }

        // ── HTML 문자열 내 텍스트 노드에서만 targetText를 찾아 replacement로 치환 ──
        // 태그/속성은 건드리지 않고, 순수 텍스트 콘텐츠만 대상으로 하여
        // 인라인 마크업(strong/em/링크 등)이 살아있는 블록에서도 안전하게 동작.
        function replaceTextInHtml(html, targetText, replacement) {
            try {
                var container = document.createElement('div');
                container.innerHTML = html;

                var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
                var fullPlain = container.textContent || '';
                var idx = fullPlain.indexOf(targetText);
                if (idx === -1) return null;

                var targetStart = idx;
                var targetEnd   = idx + targetText.length;
                var pos = 0;
                var node;
                var didReplace = false;

                while ( (node = walker.nextNode()) ) {
                    var text = node.textContent;
                    var nodeStart = pos;
                    var nodeEnd   = pos + text.length;
                    pos = nodeEnd;

                    // 이 노드가 대상 구간과 겹치는지 확인
                    var overlapStart = Math.max(nodeStart, targetStart);
                    var overlapEnd   = Math.min(nodeEnd, targetEnd);
                    if (overlapStart >= overlapEnd) continue; // 겹치지 않음

                    var localStart = overlapStart - nodeStart;
                    var localEnd   = overlapEnd - nodeStart;

                    if (!didReplace) {
                        // 첫 겹침 노드에서 교체 텍스트 전체를 삽입
                        node.textContent = text.substring(0, localStart) + replacement + text.substring(localEnd);
                        didReplace = true;
                    } else {
                        // 대상 구간이 여러 텍스트 노드에 걸친 경우, 이후 겹치는 부분은 제거만
                        node.textContent = text.substring(0, localStart) + text.substring(localEnd);
                    }
                }

                if (!didReplace) return null;
                return container.innerHTML;
            } catch(e) {
                return null;
            }
        }

        // ── 텍스트 선택 감지 ──
        $(document).on('mouseup', function(e) {
            // AI 재작성 중이면 버튼을 다시 만들지 않음
            if (isExpanding) return;
            setTimeout(function() {
                var domSel = window.getSelection();
                var text   = domSel ? domSel.toString().trim() : '';
                if (text.length > 20) {
                    selectedText = text;
                    try {
                        var range = domSel.getRangeAt(0);
                        var rect  = range.getBoundingClientRect();
                        showExpandBtn(rect.left + window.scrollX + rect.width / 2,
                                      rect.bottom + window.scrollY + 14);
                    } catch(err) { hideExpandBtn(); }
                } else {
                    if (!$(e.target).closest('#ai-expand-btn').length) hideExpandBtn();
                }
            }, 60);
        });

        $(document).on('mousedown', function(e) {
            if (isExpanding) return; // 재작성 중이면 버튼 숨기기 방지
            if (!$(e.target).closest('#ai-expand-btn').length) hideExpandBtn();
        });

        function showExpandBtn(x, y) {
            hideExpandBtn();
            $expandBtn = $('<button id="ai-expand-btn">AI로 콘텐츠 확장하기</button>').css({
                position:'absolute', left:Math.max(10, x-110)+'px', top:y+'px',
                zIndex:999999, background:'#000', color:'#fff', border:'none',
                padding:'9px 20px', borderRadius:'20px', cursor:'pointer',
                fontSize:'13px', fontWeight:'700', boxShadow:'0 4px 14px rgba(0,0,0,.35)',
                opacity:0, whiteSpace:'nowrap'
            });

            $expandBtn.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!selectedText) return;

                // ★ 핵심: 버튼 클릭 시 선택 영역을 즉시 저장 (AJAX 전)
                var saved = saveSelection();
                if (!saved) {
                    showExpandToast('텍스트를 드래그하여 선택한 후 버튼을 클릭하세요.', 'error');
                    return;
                }

                var $this = $(this);
                $this.prop('disabled', true).html('⏳ AI 재작성 중...').css({background:'#1a237e', minWidth:'160px', opacity:'1'});
                isExpanding = true; // AJAX 중 버튼 재생성 방지

                // 컨텍스트 수집
                var fullContent = '';
                var postTitle   = $('#title').val() || $('h1.wp-block-post-title').text() || '';
                try {
                    if (savedTinyEditor) {
                        fullContent = savedTinyEditor.getContent({format:'text'});
                    } else if (typeof wp !== 'undefined' && wp.data) {
                        var blocks = wp.data.select('core/block-editor').getBlocks();
                        fullContent = blocks.map(function(b) {
                            return b.attributes && b.attributes.content ? b.attributes.content : '';
                        }).join('\n');
                    }
                    if (!fullContent) fullContent = $('#content').val() || '';
                } catch(err) {}
                var plainContent = fullContent.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

                $.ajax({
                    url:     zorlinq32AiWriter.ajaxUrl,
                    type:    'POST',
                    timeout: 80000,
                    data: {
                        action:        'zorlinq32_ai_expand_content',
                        nonce:         zorlinq32AiWriter.nonce,
                        selected_text: selectedText,
                        full_content:  plainContent.substring(0, 3000),
                        post_title:    postTitle
                    },
                    success: function(res) {
                        if (res.success && res.data && res.data.expanded_text) {
                            var ok = replaceWithSaved(res.data.expanded_text);
                            if (ok) {
                                showExpandToast('AI 콘텐츠 재작성이 완료되었습니다', 'success');
                            } else {
                                showExpandToast('교체 실패: 다시 드래그 후 시도해주세요.', 'error');
                            }
                        } else {
                            showExpandToast((res.data && res.data.message) ? res.data.message : '확장 실패', 'error');
                        }
                    },
                    error: function(xhr, status) {
                        showExpandToast(status === 'timeout' ? '시간 초과. 다시 시도해주세요.' : '서버 오류가 발생했습니다.', 'error');
                    },
                    complete: function() {
                        isExpanding = false; // 재작성 완료 — 버튼 재생성 허용
                        hideExpandBtn();
                    }
                });
            });

            $('body').append($expandBtn);
            $expandBtn.animate({opacity:1}, 180);
        }

        function hideExpandBtn() {
            if ($expandBtn) {
                $expandBtn.fadeOut(150, function() { $(this).remove(); });
                $expandBtn = null;
            }
        }
    }

        /* spin 애니메이션 추가 */
    if (!$('#zorlinq32-ai-spin-style').length) {
        $('<style id="zorlinq32-ai-spin-style">@keyframes zorlinq32-ai-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>').appendTo('head');
    }

        /* ══════════════════════════════════════
       에디터에 콘텐츠 삽입 (단일 클래식 블록)
    ══════════════════════════════════════ */
    function insertContentToEditor(html, title, meta_info) {
        var editorType = detectEditorType();

        if (editorType === 'gutenberg') {
            insertToGutenbergClassicBlock(html, title);
        } else if (editorType === 'tinymce') {
            insertToTinyMCE(html, title);
        } else {
            insertToTextarea(html, title);
        }
    }

    /* ── 구텐베르크 → 단일 클래식(core/freeform) 블록으로 삽입 ──────────
       HTML 전체를 하나의 "클래식 편집기" 블록(core/freeform)에 담아 삽입.
       core/freeform 미등록 시 core/html 단일 블록으로 fallback.
       어떤 경우에도 반드시 블록 1개로 삽입.
    ─────────────────────────────────────────────────────────────────── */
    function insertToGutenbergClassicBlock(html, title) {
        if (typeof wp === 'undefined' || !wp.data || !wp.blocks) {
            insertToTinyMCE(html, title);
            return;
        }

        var dispatch = wp.data.dispatch('core/block-editor');
        if (!dispatch) { insertToTinyMCE(html, title); return; }

        // 제목 삽입
        if (title) {
            try { wp.data.dispatch('core/editor').editPost({ title: title }); } catch(e) {}
        }

        // ── 1순위: core/freeform (블록 에디터의 "클래식 편집기" 블록) ──
        var registeredTypes = wp.blocks.getBlockTypes ? wp.blocks.getBlockTypes() : [];
        var hasFreeform = registeredTypes.some(function(t) { return t.name === 'core/freeform'; });

        if (hasFreeform) {
            try {
                var classicBlock = wp.blocks.createBlock('core/freeform', { content: html });
                dispatch.resetBlocks([classicBlock]);
                return;
            } catch(e) {
                console.warn('core/freeform 삽입 실패, core/html로 대체:', e);
            }
        }

        // ── 2순위: core/html (Custom HTML 단일 블록) ──
        try {
            var htmlBlock = wp.blocks.createBlock('core/html', { content: html });
            dispatch.resetBlocks([htmlBlock]);
        } catch(e) {
            console.error('core/html 삽입도 실패:', e);
            insertToTinyMCE(html, title);
        }
    }

    /* ── HTML 문자열 → WP 블록 배열 변환 (콘텐츠 확장 기능용으로 유지) ─── */
    function htmlToBlocks(html) {
        // DOM 파서로 HTML 파싱
        var parser = new DOMParser();
        var doc    = parser.parseFromString('<div id="root">' + html + '</div>', 'text/html');
        var root   = doc.getElementById('root');
        if (!root) return [];

        var blocks = [];
        var children = Array.prototype.slice.call(root.childNodes);

        // 인접한 인라인/p 이외 요소들을 그룹화하는 버퍼
        var groupBuf = [];

        function flushGroup() {
            if (groupBuf.length === 0) return;
            var combined = groupBuf.join('').trim();
            groupBuf = [];
            if (!combined) return;
            blocks.push(wp.blocks.createBlock('core/html', { content: combined }));
        }

        children.forEach(function(node) {
            // 텍스트 노드(공백 등) 스킵
            if (node.nodeType === 3) {
                if (node.textContent.trim()) groupBuf.push(node.textContent);
                return;
            }
            if (node.nodeType !== 1) return;

            var tag = node.tagName.toLowerCase();

            // ── H2 / H3 / H4 → core/heading ──
            if (tag === 'h2' || tag === 'h3' || tag === 'h4') {
                flushGroup();
                var level = parseInt(tag.charAt(1), 10);
                blocks.push(wp.blocks.createBlock('core/heading', {
                    level:   level,
                    content: node.innerHTML.trim()
                }));
                return;
            }

            // ── P → core/paragraph ──
            if (tag === 'p') {
                flushGroup();
                var pContent = node.innerHTML.trim();
                if (!pContent) return;
                blocks.push(wp.blocks.createBlock('core/paragraph', {
                    content: pContent
                }));
                return;
            }

            // ── UL → core/list (unordered) ──
            if (tag === 'ul') {
                flushGroup();
                var ulItems = extractListItems(node);
                blocks.push(wp.blocks.createBlock('core/list', {
                    ordered: false,
                    values:  ulItems
                }));
                return;
            }

            // ── OL → core/list (ordered) ──
            if (tag === 'ol') {
                flushGroup();
                var olItems = extractListItems(node);
                blocks.push(wp.blocks.createBlock('core/list', {
                    ordered: true,
                    values:  olItems
                }));
                return;
            }

            // ── TABLE → core/table ──
            if (tag === 'table') {
                flushGroup();
                var tableBlock = buildTableBlock(node);
                if (tableBlock) blocks.push(tableBlock);
                return;
            }

            // ── DIV / SECTION 등 컨테이너 → 내용 재귀 파싱 ──
            if (tag === 'div' || tag === 'section' || tag === 'article') {
                flushGroup();
                var innerHtml = node.innerHTML.trim();
                if (innerHtml) {
                    var innerBlocks = htmlToBlocks(innerHtml);
                    innerBlocks.forEach(function(b) { blocks.push(b); });
                }
                return;
            }

            // ── 그 외(span, strong, em, a, br 등) → core/html 버퍼에 쌓기 ──
            groupBuf.push(node.outerHTML);
        });

        flushGroup();

        // 빈 블록 최종 필터링
        blocks = blocks.filter(function(b) {
            if (!b) return false;
            var attr = b.attributes || {};
            if (b.name === 'core/paragraph' && !attr.content) return false;
            if (b.name === 'core/heading'   && !attr.content) return false;
            return true;
        });

        // 완전히 비었으면 core/html 하나로 fallback
        if (blocks.length === 0) {
            blocks.push(wp.blocks.createBlock('core/html', { content: html }));
        }

        return blocks;
    }

    /* ── li 항목 innerHTML 배열 추출 ── */
    function extractListItems(ulNode) {
        // core/list가 기대하는 형식: <li>내용</li><li>내용</li> 연결 문자열
        var liNodes = ulNode.querySelectorAll(':scope > li');
        var parts   = [];
        liNodes.forEach(function(li) {
            parts.push('<li>' + li.innerHTML.trim() + '</li>');
        });
        return parts.join('');
    }

    /* ── TABLE → core/table 블록 빌드 ── */
    function buildTableBlock(tableNode) {
        var head = [], body = [];

        // thead
        var theadRows = tableNode.querySelectorAll('thead tr');
        theadRows.forEach(function(tr) {
            var cells = [];
            tr.querySelectorAll('th, td').forEach(function(cell) {
                cells.push({ content: cell.innerHTML.trim() });
            });
            head.push({ cells: cells });
        });

        // tbody (또는 thead 없이 바로 tr)
        var tbodyRows = tableNode.querySelectorAll('tbody tr');
        if (tbodyRows.length === 0) {
            tbodyRows = tableNode.querySelectorAll('tr');
        }
        tbodyRows.forEach(function(tr) {
            // thead의 tr은 건너뜀
            if (tr.parentNode.tagName && tr.parentNode.tagName.toLowerCase() === 'thead') return;
            var cells = [];
            tr.querySelectorAll('th, td').forEach(function(cell) {
                cells.push({ content: cell.innerHTML.trim() });
            });
            if (cells.length) body.push({ cells: cells });
        });

        if (body.length === 0 && head.length === 0) return null;

        return wp.blocks.createBlock('core/table', {
            hasFixedLayout: false,
            head:           head,
            body:           body,
            foot:           []
        });
    }

    function insertToTinyMCE(html, title) {
        try {
            var ed = tinymce.get('content');
            if (ed) {
                ed.setContent(html);
            }
            if (title && $('#title').length) $('#title').val(title);
        } catch(e) { console.error('TinyMCE 삽입 오류:', e); }
    }

    function insertToTextarea(html, title) {
        try {
            var $c = $('#content');
            if ($c.length) $c.val(html);
            if (title && $('#title').length) $('#title').val(title);
        } catch(e) { console.error('Textarea 삽입 오류:', e); }
    }

    function detectEditorType() {
        try {
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor') &&
                wp.data.select('core/block-editor').getBlocks) {
                return 'gutenberg';
            }
        } catch(e) {}
        try {
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) return 'tinymce';
        } catch(e) {}
        if ($('#content').length && $('#content').is('textarea')) return 'textarea';
        return null;
    }

    function getEditorContent() {
        try {
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                var content = wp.data.select('core/editor').getEditedPostContent();
                return content ? content.substring(0, 5000) : '';
            }
        } catch(e) {}
        try {
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                return tinymce.get('content').getContent().substring(0, 5000);
            }
        } catch(e) {}
        try { return $('#content').val().substring(0, 5000) || ''; } catch(e) {}
        return '';
    }

    /* ══════════════════════════════════════
       진행률 유틸
    ══════════════════════════════════════ */
    function showProgress($p, msg, pct) {
        pct = Math.max(0, Math.min(100, Math.round(pct)));
        $p.find('.zorlinq32-ai-progress-fill').css('width', pct + '%');
        $p.find('.progress-label').text(msg);
        $p.find('.progress-percent').text(pct + '%');
        $p.show();
    }

    /* safeProgress: 절대 감소 없음, 100% 초과 없음 */
    function safeProgress($p, pct, msg) {
        pct = Math.max(0, Math.min(100, Math.round(pct)));
        var $fill = $p.find('.zorlinq32-ai-progress-fill');
        // 현재 width 파싱
        var curW  = parseFloat($fill[0] ? $fill[0].style.width : '0') || 0;
        // 절대 감소 금지
        if (pct <= curW) return;
        $fill.css('width', pct + '%');
        if (msg) $p.find('.progress-label').text(msg);
        $p.find('.progress-percent').text(pct + '%');
    }

    /* 하위 호환: 기존 smoothProgress 호출 부분이 남아있을 경우 대비 */
    function smoothProgress($p, target, dur, msg) {
        safeProgress($p, target, msg);
    }

    function hideProgress($p) {
        $p.fadeOut(300, function() {
            $(this).find('.zorlinq32-ai-progress-fill').css('width', '0%');
            $(this).find('.progress-label').text('AI 처리 시작 중');
            $(this).find('.progress-percent').text('0%');
            $(this).hide();
        });
    }

    function showResult(msg, type) {
        var $r = $('#zorlinq32-ai-result');
        var bg = type === 'success' ? '#d4edda' : (type === 'error' ? '#f8d7da' : '#d1ecf1');
        var bc = type === 'success' ? '#c3e6cb' : (type === 'error' ? '#f5c6cb' : '#bee5eb');
        var tc = type === 'success' ? '#155724' : (type === 'error' ? '#721c24' : '#0c5460');
        $r.html(msg).css({ 'background-color': bg, 'border-color': bc, 'color': tc }).fadeIn(200);
    }

    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

})(jQuery);
