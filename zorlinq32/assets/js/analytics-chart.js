/**
 * Zorlinq32 - 애널리틱스 그래프 렌더러
 * 외부 차트 라이브러리(Chart.js 등) 없이 순수 SVG로 부드러운 곡선 그래프를 그립니다.
 * 데이터는 PHP가 렌더링한 <script type="application/json"> 태그를 통해 전달받습니다.
 *
 * [애널리틱스 개선] 이 파일은 두 곳에서 재사용됩니다:
 * 1. 애널리틱스 상세 페이지의 큰 그래프 (#zorlinq32-trend-chart / #zorlinq32-trend-data)
 * 2. 워드프레스 알림판·플러그인 대시보드의 미니 그래프 (#zorlinq32-dashboard-mini-chart / #zorlinq32-dashboard-mini-data)
 * 두 곳 모두 "방문자수(visitors, 중복 제거)"와 "조회수(count, 페이지뷰)" 두 계열을 함께 그리고,
 * 그래프 위에 마우스를 올리면(또는 모바일에서 터치하면) 해당 날짜의 정확한 수치를 보여주는
 * 커스텀 툴팁을 표시합니다. 브라우저 기본 title 툴팁(지연 크고 스타일 불가)을 대체합니다.
 */
( function () {
	'use strict';

	var CHART_TARGETS = [
		{ containerId: 'zorlinq32-trend-chart', dataId: 'zorlinq32-trend-data', height: 220 },
		{ containerId: 'zorlinq32-dashboard-mini-chart', dataId: 'zorlinq32-dashboard-mini-data', height: 110 },
	];

	document.addEventListener( 'DOMContentLoaded', function () {
		CHART_TARGETS.forEach( function ( target ) {
			var container = document.getElementById( target.containerId );
			var dataScript = document.getElementById( target.dataId );

			if ( ! container || ! dataScript ) {
				return;
			}

			var points;
			try {
				points = JSON.parse( dataScript.textContent );
			} catch ( e ) {
				return;
			}

			if ( ! Array.isArray( points ) || points.length === 0 ) {
				return;
			}

			renderSmoothLineChart( container, points, target.height );

			// [버그 수정] 컨테이너가 처음 그려질 때 폭이 0으로 측정되면(예: 숨겨진 탭 안에
			// 있다가 나중에 보이게 되는 레이아웃) 그래프가 찌그러진 채로 고정될 수 있었습니다.
			// 짧은 시간 동안 폭을 다시 확인해, 0이었다가 정상값으로 바뀌면 한 번 더 그립니다.
			if ( 0 === container.clientWidth ) {
				var retries = 0;
				var retryTimer = window.setInterval( function () {
					retries++;
					if ( container.clientWidth > 0 ) {
						renderSmoothLineChart( container, points, target.height );
						window.clearInterval( retryTimer );
					} else if ( retries > 20 ) {
						window.clearInterval( retryTimer ); // 최대 2초까지만 재시도합니다.
					}
				}, 100 );
			}

			// 창 크기가 바뀌면(반응형 레이아웃, 사이드바 접힘 등) 그래프 폭이 컨테이너와
			// 어긋날 수 있으므로 다시 그립니다. 리사이즈 중 과도한 재계산을 막기 위해
			// 짧은 디바운스를 둡니다.
			var resizeTimer = null;
			window.addEventListener( 'resize', function () {
				window.clearTimeout( resizeTimer );
				resizeTimer = window.setTimeout( function () {
					renderSmoothLineChart( container, points, target.height );
				}, 150 );
			} );
		} );
	} );

	/**
	 * Catmull-Rom 스플라인을 3차 베지어 곡선으로 변환해 부드러운 라인을 그립니다.
	 * visitors(방문자수)를 주 계열로, count(조회수)를 보조 계열로 함께 그립니다.
	 */
	function renderSmoothLineChart( container, points, chartHeight ) {
		var width  = container.clientWidth || 600;
		var height = chartHeight || 220;
		var isMini = height <= 130;
		var padding = isMini
			? { top: 10, right: 8, bottom: 16, left: 8 }
			: { top: 20, right: 20, bottom: 30, left: 40 };
		var plotWidth  = width - padding.left - padding.right;
		var plotHeight = height - padding.top - padding.bottom;

		// 방문자수와 조회수 중 더 큰 값을 기준으로 y축 스케일을 잡아, 두 선이 같은 좌표계 안에서
		// 비교 가능하게 표시되도록 합니다.
		var maxValue = 0;
		points.forEach( function ( p ) {
			maxValue = Math.max( maxValue, Number( p.visitors || 0 ), Number( p.count || 0 ) );
		} );
		maxValue = maxValue === 0 ? 1 : maxValue;

		var stepX = points.length > 1 ? plotWidth / ( points.length - 1 ) : 0;

		function toCoords( key ) {
			return points.map( function ( p, i ) {
				var value = Number( p[ key ] || 0 );
				var x = padding.left + ( stepX * i );
				var y = padding.top + plotHeight - ( ( value / maxValue ) * plotHeight );
				return { x: x, y: y, value: value, date: p.date };
			} );
		}

		var visitorCoords = toCoords( 'visitors' );
		var pageviewCoords = toCoords( 'count' );

		var visitorLine = buildSmoothPath( visitorCoords );
		var areaPath = visitorCoords.length
			? visitorLine + ' L ' + visitorCoords[ visitorCoords.length - 1 ].x + ' ' + ( padding.top + plotHeight ) +
				' L ' + visitorCoords[0].x + ' ' + ( padding.top + plotHeight ) + ' Z'
			: '';
		var pageviewLine = buildSmoothPath( pageviewCoords );

		var svgNS = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( svgNS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + width + ' ' + height );
		svg.setAttribute( 'width', '100%' );
		svg.setAttribute( 'height', height );
		svg.style.overflow = 'visible';

		// 그라데이션 정의 (영역 채우기용). 여러 그래프 인스턴스가 한 페이지에 있을 수 있어
		// gradient id가 충돌하지 않도록 컨테이너 id를 접미사로 사용합니다.
		var gradId = 'zorlinq32-area-gradient-' + container.id;
		var defs = document.createElementNS( svgNS, 'defs' );
		var gradient = document.createElementNS( svgNS, 'linearGradient' );
		gradient.setAttribute( 'id', gradId );
		gradient.setAttribute( 'x1', '0' );
		gradient.setAttribute( 'y1', '0' );
		gradient.setAttribute( 'x2', '0' );
		gradient.setAttribute( 'y2', '1' );
		var stop1 = document.createElementNS( svgNS, 'stop' );
		stop1.setAttribute( 'offset', '0%' );
		stop1.setAttribute( 'stop-color', '#4f46e5' );
		stop1.setAttribute( 'stop-opacity', '0.25' );
		var stop2 = document.createElementNS( svgNS, 'stop' );
		stop2.setAttribute( 'offset', '100%' );
		stop2.setAttribute( 'stop-color', '#4f46e5' );
		stop2.setAttribute( 'stop-opacity', '0' );
		gradient.appendChild( stop1 );
		gradient.appendChild( stop2 );
		defs.appendChild( gradient );
		svg.appendChild( defs );

		// 영역(area) 채우기 - 방문자수 기준
		if ( areaPath ) {
			var area = document.createElementNS( svgNS, 'path' );
			area.setAttribute( 'd', areaPath );
			area.setAttribute( 'fill', 'url(#' + gradId + ')' );
			area.setAttribute( 'stroke', 'none' );
			svg.appendChild( area );
		}

		// 조회수(보조 계열) 라인 - 미니 그래프에서는 생략해 시각적으로 단순하게 유지합니다.
		if ( ! isMini && pageviewLine ) {
			var pvLine = document.createElementNS( svgNS, 'path' );
			pvLine.setAttribute( 'd', pageviewLine );
			pvLine.setAttribute( 'fill', 'none' );
			pvLine.setAttribute( 'stroke', '#c3c4c7' );
			pvLine.setAttribute( 'stroke-width', '2' );
			pvLine.setAttribute( 'stroke-linecap', 'round' );
			pvLine.setAttribute( 'stroke-linejoin', 'round' );
			pvLine.setAttribute( 'stroke-dasharray', '4 3' );
			svg.appendChild( pvLine );
		}

		// 방문자수(주 계열) 라인
		if ( visitorLine ) {
			var line = document.createElementNS( svgNS, 'path' );
			line.setAttribute( 'd', visitorLine );
			line.setAttribute( 'fill', 'none' );
			line.setAttribute( 'stroke', '#4f46e5' );
			line.setAttribute( 'stroke-width', isMini ? '2' : '2.5' );
			line.setAttribute( 'stroke-linecap', 'round' );
			line.setAttribute( 'stroke-linejoin', 'round' );
			svg.appendChild( line );
		}

		// 데이터 포인트(작은 원). 미니 그래프에서는 시각적 잡음을 줄이기 위해 생략합니다.
		if ( ! isMini ) {
			visitorCoords.forEach( function ( c ) {
				var circle = document.createElementNS( svgNS, 'circle' );
				circle.setAttribute( 'cx', c.x );
				circle.setAttribute( 'cy', c.y );
				circle.setAttribute( 'r', '3' );
				circle.setAttribute( 'fill', '#ffffff' );
				circle.setAttribute( 'stroke', '#4f46e5' );
				circle.setAttribute( 'stroke-width', '1.5' );
				svg.appendChild( circle );
			} );
		}

		// ---- 커스텀 hover 툴팁: 수직 가이드라인 + 강조점 + 정보 박스 ----
		var hoverLine = document.createElementNS( svgNS, 'line' );
		hoverLine.setAttribute( 'class', 'zorlinq32-chart-hover-line' );
		hoverLine.setAttribute( 'y1', padding.top );
		hoverLine.setAttribute( 'y2', padding.top + plotHeight );
		hoverLine.style.opacity = '0';
		svg.appendChild( hoverLine );

		var hoverDotVisitors = document.createElementNS( svgNS, 'circle' );
		hoverDotVisitors.setAttribute( 'class', 'zorlinq32-chart-hover-dot' );
		hoverDotVisitors.setAttribute( 'r', '5' );
		hoverDotVisitors.setAttribute( 'fill', '#4f46e5' );
		hoverDotVisitors.setAttribute( 'stroke', '#fff' );
		hoverDotVisitors.setAttribute( 'stroke-width', '2' );
		hoverDotVisitors.style.opacity = '0';
		svg.appendChild( hoverDotVisitors );

		var hoverDotPageviews = null;
		if ( ! isMini ) {
			hoverDotPageviews = document.createElementNS( svgNS, 'circle' );
			hoverDotPageviews.setAttribute( 'class', 'zorlinq32-chart-hover-dot' );
			hoverDotPageviews.setAttribute( 'r', '4' );
			hoverDotPageviews.setAttribute( 'fill', '#c3c4c7' );
			hoverDotPageviews.setAttribute( 'stroke', '#fff' );
			hoverDotPageviews.setAttribute( 'stroke-width', '1.5' );
			hoverDotPageviews.style.opacity = '0';
			svg.appendChild( hoverDotPageviews );
		}

		// 넓은 투명 히트 영역 - 실제 마우스/터치 이벤트를 받아 가장 가까운 데이터 포인트를 찾습니다.
		// [버그 수정] fill="transparent"인 SVG 요소는 기본 pointer-events 값(visiblePainted)
		// 기준으로 "칠해지지 않은 것"으로 취급되어 마우스 이벤트를 아예 받지 못하는 브라우저가
		// 있습니다. 이 때문에 그래프 위에 마우스를 올려도 hover 툴팁이 전혀 표시되지 않는
		// 문제가 있었습니다. pointer-events="all"을 명시해 투명하더라도 항상 이벤트를
		// 받도록 강제합니다.
		var overlay = document.createElementNS( svgNS, 'rect' );
		overlay.setAttribute( 'x', padding.left );
		overlay.setAttribute( 'y', 0 );
		overlay.setAttribute( 'width', Math.max( plotWidth, 1 ) );
		overlay.setAttribute( 'height', height );
		// fill-opacity를 극소값으로 둬 시각적으로는 투명하지만, 일부 구형 브라우저 엔진이
		// fill="transparent"만으로는 "칠해진 요소"로 인식하지 못하는 경우까지 대비합니다.
		overlay.setAttribute( 'fill', '#ffffff' );
		overlay.setAttribute( 'fill-opacity', '0.001' );
		overlay.setAttribute( 'pointer-events', 'all' );
		svg.appendChild( overlay );

		container.innerHTML = '';
		container.style.position = 'relative';
		container.appendChild( svg );

		var tooltip = document.createElement( 'div' );
		tooltip.className = 'zorlinq32-chart-tooltip';
		container.appendChild( tooltip );

		function findNearestIndex( mouseX ) {
			if ( stepX === 0 ) {
				return 0;
			}
			var relativeX = mouseX - padding.left;
			var index = Math.round( relativeX / stepX );
			return Math.min( Math.max( index, 0 ), points.length - 1 );
		}

		function showTooltip( index, clientX ) {
			var point = points[ index ];
			var vCoord = visitorCoords[ index ];
			var pCoord = pageviewCoords[ index ];

			hoverLine.setAttribute( 'x1', vCoord.x );
			hoverLine.setAttribute( 'x2', vCoord.x );
			hoverLine.style.opacity = '1';

			hoverDotVisitors.setAttribute( 'cx', vCoord.x );
			hoverDotVisitors.setAttribute( 'cy', vCoord.y );
			hoverDotVisitors.style.opacity = '1';

			if ( hoverDotPageviews ) {
				hoverDotPageviews.setAttribute( 'cx', pCoord.x );
				hoverDotPageviews.setAttribute( 'cy', pCoord.y );
				hoverDotPageviews.style.opacity = '1';
			}

			var labels = ( window.zorlinq32AnalyticsChartI18n || {} );
			var visitorsLabel = labels.visitors || '방문자수';
			var pageviewsLabel = labels.pageviews || '조회수';

			var html = '<div class="date">' + escapeHtml( point.date ) + '</div>';
			html += '<div class="metric"><span><span class="dot" style="background:#4f46e5;"></span>' + escapeHtml( visitorsLabel ) + '</span><strong>' + Number( point.visitors || 0 ).toLocaleString() + '</strong></div>';
			if ( ! isMini ) {
				html += '<div class="metric"><span><span class="dot" style="background:#c3c4c7;"></span>' + escapeHtml( pageviewsLabel ) + '</span><strong>' + Number( point.count || 0 ).toLocaleString() + '</strong></div>';
			}
			tooltip.innerHTML = html;

			// 툴팁이 그래프 좌우 경계를 넘어가지 않도록 위치를 보정합니다.
			var containerRect = container.getBoundingClientRect();
			var relativeLeft = vCoord.x;
			var tooltipWidthEstimate = 140;
			if ( relativeLeft + tooltipWidthEstimate / 2 > containerRect.width ) {
				relativeLeft = containerRect.width - tooltipWidthEstimate / 2;
			}
			if ( relativeLeft - tooltipWidthEstimate / 2 < 0 ) {
				relativeLeft = tooltipWidthEstimate / 2;
			}

			tooltip.style.left = relativeLeft + 'px';
			tooltip.style.top = Math.max( vCoord.y - 10, 20 ) + 'px';
			tooltip.classList.add( 'is-visible' );
		}

		function hideTooltip() {
			hoverLine.style.opacity = '0';
			hoverDotVisitors.style.opacity = '0';
			if ( hoverDotPageviews ) {
				hoverDotPageviews.style.opacity = '0';
			}
			tooltip.classList.remove( 'is-visible' );
		}

		function handlePointerMove( evt ) {
			var rect = svg.getBoundingClientRect();
			var clientX = ( evt.touches && evt.touches[0] ) ? evt.touches[0].clientX : evt.clientX;
			var scaleX = rect.width ? ( width / rect.width ) : 1;
			var mouseX = ( clientX - rect.left ) * scaleX;
			var index = findNearestIndex( mouseX );
			showTooltip( index, clientX );
		}

		overlay.addEventListener( 'mousemove', handlePointerMove );
		overlay.addEventListener( 'mouseleave', hideTooltip );
		overlay.addEventListener( 'touchstart', handlePointerMove, { passive: true } );
		overlay.addEventListener( 'touchmove', handlePointerMove, { passive: true } );
		overlay.addEventListener( 'touchend', hideTooltip );
	}

	/**
	 * 점 배열을 Catmull-Rom 방식으로 부드럽게 잇는 SVG path의 "d" 속성 문자열을 만듭니다.
	 */
	function buildSmoothPath( coords ) {
		if ( ! coords || coords.length === 0 ) {
			return '';
		}
		if ( coords.length < 2 ) {
			return 'M ' + coords[0].x + ' ' + coords[0].y;
		}

		var d = 'M ' + coords[0].x + ' ' + coords[0].y;

		for ( var i = 0; i < coords.length - 1; i++ ) {
			var p0 = coords[ i - 1 ] || coords[ i ];
			var p1 = coords[ i ];
			var p2 = coords[ i + 1 ];
			var p3 = coords[ i + 2 ] || p2;

			var cp1x = p1.x + ( p2.x - p0.x ) / 6;
			var cp1y = p1.y + ( p2.y - p0.y ) / 6;
			var cp2x = p2.x - ( p3.x - p1.x ) / 6;
			var cp2y = p2.y - ( p3.y - p1.y ) / 6;

			d += ' C ' + cp1x + ' ' + cp1y + ', ' + cp2x + ' ' + cp2y + ', ' + p2.x + ' ' + p2.y;
		}

		return d;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str == null ? '' : str );
		return div.innerHTML;
	}
} )();
