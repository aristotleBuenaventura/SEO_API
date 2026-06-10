#!/usr/bin/env python3
"""Generate standalone HTML preview for Table_Standing_Fifa.txt"""

import html
import json
import re
import urllib.request
from datetime import datetime
from pathlib import Path

API_KEY = "75d4149d2873e1afd455cebbd0ec25255cea79295120d9726c22fdca3ed306be"
PORTUGAL_LOGO = "https://img-live.bannershive.dev/h001_uploads/images/portugal.jpg"
TBA_LOGO = "/wp-content/uploads/2025/09/tba.webp"
TBD_SHIELD = (
    '<span class="wc-ko-shield" aria-hidden="true">'
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 22" role="img" aria-label="TBD">'
    '<path fill="#555555" d="M9 0 1.8 3.1v6.4c0 4.3 3.1 8 7.2 9 4.1-1 7.2-4.7 7.2-9V3.1L9 0z"/>'
    "</svg></span>"
)


def api_get(url: str):
    with urllib.request.urlopen(url, timeout=15) as resp:
        return json.loads(resp.read().decode())


def is_tbd(name: str) -> bool:
    n = (name or "").strip().upper()
    return n in ("", "TBD", "TBA")


def is_portugal(name: str) -> bool:
    return "portugal" in (name or "").strip().lower()


def team_logo(logo: str, team_name: str) -> str:
    if logo:
        return logo
    if is_portugal(team_name):
        return PORTUGAL_LOGO
    return TBA_LOGO


def ko_round_key(league_round: str):
    lr = (league_round or "").strip().lower()
    if not lr:
        return None
    if re.search(r"\b(round of 32|1/16|16th.?final|round of thirty-two)\b", lr):
        return "r32"
    if re.search(r"\b(round of 16|1/8|8th.?final|round of sixteen)\b", lr):
        return "r16"
    if re.search(r"\bquarter-?finals?\b", lr):
        return "qf"
    if re.search(r"\bsemi-?finals?\b", lr):
        return "sf"
    if re.search(r"\b(3rd|third place)\b", lr):
        return "third"
    if re.search(r"\bfinal\b", lr) and not re.search(r"quarter|semi", lr):
        return "final"
    return None


def parse_scores(fixture):
    home_score = away_score = home_pen = away_pen = None
    result = fixture.get("event_final_result") or ""
    if result and result != "-":
        m = re.search(r"(\d+)\s*-\s*(\d+)", result)
        if m:
            home_score, away_score = int(m.group(1)), int(m.group(2))
    pen = fixture.get("event_penalty_result") or ""
    if pen:
        pm = re.search(r"(\d+)\s*-\s*(\d+)", pen)
        if pm:
            home_pen, away_pen = int(pm.group(1)), int(pm.group(2))
    winner = None
    if home_score is not None and away_score is not None:
        if home_score > away_score:
            winner = "home"
        elif away_score > home_score:
            winner = "away"
        elif home_pen is not None and away_pen is not None:
            winner = "home" if home_pen > away_pen else "away" if away_pen > home_pen else None
    return home_score, away_score, home_pen, away_pen, winner


def status_label(fixture):
    if fixture.get("event_penalty_result"):
        return "FT (P)"
    status = fixture.get("event_status") or ""
    if "Finished" in status or status == "FT":
        return "FT"
    if "Not Started" in status or status == "":
        return ""
    return status


def format_ko_date(date_str, time_str=""):
    if not date_str:
        return ""
    for fmt in (f"{date_str} {time_str}".strip(), date_str):
        try:
            ts = datetime.strptime(fmt[:19], "%Y-%m-%d %H:%M:%S") if " " in fmt and ":" in fmt else datetime.strptime(fmt[:10], "%Y-%m-%d")
            if time_str and time_str.strip():
                return ts.strftime("%b %-d, %-I:%M %p")
            return ts.strftime("%b %-d, %y")
        except ValueError:
            continue
    return ""


def ko_match_teams(match):
    return [t for t in [match.get("event_home_team", ""), match.get("event_away_team", "")] if t]


def ko_feeds_parent(child, parent):
    child_teams = ko_match_teams(child)
    parent_teams = ko_match_teams(parent)
    return any(t in parent_teams for t in child_teams)


def sort_ko_by_date(matches):
    return sorted(matches, key=lambda m: (m.get("event_date") or "", m.get("event_key") or 0))


def sort_ko_pair_for_parent(pair, parent):
    if len(pair) != 2:
        return sort_ko_by_date(pair)
    parent_teams = ko_match_teams(parent)
    anchor = parent_teams[0] if parent_teams else ""
    if not anchor:
        return sort_ko_by_date(pair)

    def key(m):
        has = anchor in ko_match_teams(m)
        return (-int(has), m.get("event_date") or "", m.get("event_key") or 0)

    return sorted(pair, key=key)


def sort_ko_children_for_parents(children, parents):
    sorted_matches, used = [], set()
    for parent in parents:
        pair = []
        for idx, child in enumerate(children):
            if idx in used or not ko_feeds_parent(child, parent):
                continue
            pair.append(child)
            used.add(idx)
            if len(pair) == 2:
                break
        if len(pair) == 2:
            pair = sort_ko_pair_for_parent(pair, parent)
        sorted_matches.extend(pair)
    for idx, child in enumerate(children):
        if idx not in used:
            sorted_matches.append(child)
    return sorted_matches


def sort_knockout_columns(columns):
    round_order = ["final", "sf", "qf", "r16", "r32"]
    if columns["final"]["matches"]:
        columns["final"]["matches"] = sort_ko_by_date(columns["final"]["matches"])
    for i in range(len(round_order) - 1):
        parent_key, child_key = round_order[i], round_order[i + 1]
        if not columns[child_key]["matches"]:
            continue
        if columns[parent_key]["matches"]:
            columns[child_key]["matches"] = sort_ko_children_for_parents(
                columns[child_key]["matches"], columns[parent_key]["matches"]
            )
        else:
            columns[child_key]["matches"] = sort_ko_by_date(columns[child_key]["matches"])
    return columns


def ko_leaf_count(columns):
    for key in ("r32", "r16"):
        if columns[key]["matches"]:
            return len(columns[key]["matches"])
    for key in ("qf", "sf", "final"):
        if columns[key]["matches"]:
            return max(1, len(columns[key]["matches"]) * 2)
    return 1


def ko_slot_grid_style(index, match_count, leaf_count):
    match_count = max(1, int(match_count))
    leaf_count = max(1, int(leaf_count))
    span = max(1, round(leaf_count / match_count))
    start = int(index * leaf_count / match_count) + 1
    return f"grid-row:{start} / span {span};align-self:center;"


def build_knockout_bracket(fixtures):
    columns = {
        "r32": {"label": "Round of 32", "matches": []},
        "r16": {"label": "Round of 16", "matches": []},
        "qf": {"label": "Quarter-finals", "matches": []},
        "sf": {"label": "Semi-finals", "matches": []},
        "final": {"label": "Finals", "matches": []},
    }
    for fixture in fixtures:
        key = ko_round_key(fixture.get("league_round", ""))
        if key == "third":
            key = "final"
        if key and key in columns:
            columns[key]["matches"].append(fixture)
    return sort_knockout_columns(columns)


def render_ko_icon(logo, team_name):
    if is_tbd(team_name):
        return TBD_SHIELD
    return f'<img src="{html.escape(team_logo(logo, team_name))}" alt="">'


def render_ko_card(match):
    home_score, away_score, home_pen, away_pen, winner = parse_scores(match)
    status = status_label(match)
    home = (match.get("event_home_team") or "").strip()
    away = (match.get("event_away_team") or "").strip()
    if is_tbd(home):
        home = "TBD"
    if is_tbd(away):
        away = "TBD"
    date = format_ko_date(match.get("event_date", ""), match.get("event_time", "")) or "TBD"

    teams = [
        ("home", home, match.get("home_team_logo", ""), home_score, home_pen),
        ("away", away, match.get("away_team_logo", ""), away_score, away_pen),
    ]
    rows = []
    for side, name, logo, score, pen in teams:
        is_winner = winner == side
        score_txt = ""
        if score is not None:
            score_txt = str(score)
            if pen is not None:
                score_txt += f" ({pen})"
        elif not is_tbd(name):
            score_txt = "-"
        score_html = f'<span class="wc-ko-score">{html.escape(score_txt)}</span>' if score_txt else ""
        rows.append(
            f'<div class="wc-ko-team{" is-winner" if is_winner else ""}">'
            f'<span class="wc-ko-arrow"></span>{render_ko_icon(logo, name)}'
            f'<span class="wc-ko-team-name">{html.escape(name)}</span>{score_html}</div>'
        )

    return (
        f'<div class="wc-ko-card"><div class="wc-ko-meta">'
        f'<span class="wc-ko-date">{html.escape(date)}</span>'
        f'<span class="wc-ko-status">{html.escape(status)}</span></div>'
        + "".join(rows)
        + "</div>"
    )


def fetch_standings(season):
    url = (
        "https://apiv2.allsportsapi.com/football/?met=Standings"
        f"&APIkey={API_KEY}&leagueId=28&season={season}"
    )
    data = api_get(url)
    return data.get("result", {}).get("total") or []


def fetch_fixtures(season):
    ranges = {"2026": ("2026-06-01", "2026-07-31"), "2022": ("2022-11-20", "2022-12-20")}
    start, end = ranges.get(season, ("2026-06-01", "2026-07-31"))
    url = (
        "https://apiv2.allsportsapi.com/football/?met=Fixtures"
        f"&APIkey={API_KEY}&from={start}&to={end}&leagueId=28"
    )
    fixtures = api_get(url).get("result") or []
    return [f for f in fixtures if str(f.get("league_season", "")) == str(season)]


def group_standings(data):
    groups = {}
    for team in data:
        group = team.get("league_round") or "Unknown Group"
        if "third-placed" in group.lower():
            continue
        if not re.match(r"^Group\s+", group, re.I):
            continue
        groups.setdefault(group, []).append(team)
    for group in groups:
        groups[group].sort(key=lambda t: t.get("standing_place", 99))
    return dict(sorted(groups.items()))


def main():
    standings = fetch_standings("2026")
    groups = group_standings(standings)

    ko_fixtures = fetch_fixtures("2026")
    ko_note = ""
    ko_columns = build_knockout_bracket(ko_fixtures)
    has_ko = any(col["matches"] for col in ko_columns.values())
    if not has_ko:
        ko_fixtures = fetch_fixtures("2022")
        ko_columns = build_knockout_bracket(ko_fixtures)
        ko_note = "Knockout bracket uses 2022 sample data — 2026 knockouts not in API yet."

    ko_leaf_rows = ko_leaf_count(ko_columns)
    uid = "wc_standings_preview"

    group_html = []
    for group_name, teams in groups.items():
        rows = []
        for t in teams:
            logo = html.escape(team_logo(t.get("team_logo", ""), t.get("standing_team", "")))
            rows.append(
                f'<div class="wc-row"><div class="wc-team-cell">'
                f'<span class="wc-rank">{html.escape(str(t.get("standing_place", "")))}</span>'
                f'<img src="{logo}" alt="">'
                f'<span class="wc-name">{html.escape(t.get("standing_team", ""))}</span></div>'
                f'<div class="wc-val-stat">{t.get("standing_P", 0)}</div>'
                f'<div class="wc-val-stat">{t.get("standing_W", 0)}</div>'
                f'<div class="wc-val-stat">{t.get("standing_D", 0)}</div>'
                f'<div class="wc-val-stat">{t.get("standing_L", 0)}</div>'
                f'<div class="wc-val-stat">{t.get("standing_F", 0)}</div>'
                f'<div class="wc-val-stat">{t.get("standing_A", 0)}</div>'
                f'<div class="wc-val-stat">{t.get("standing_GD", 0)}</div>'
                f'<div class="wc-val-pts">{t.get("standing_PTS", 0)}</div></div>'
            )
        group_html.append(
            f'<div class="wc-group"><h3 class="wc-group-title">{html.escape(group_name)}</h3>'
            f'<div class="wc-row wc-row-head"><div class="wc-lbl-team">Team</div>'
            + "".join(f'<div class="wc-lbl-stat">{lbl}</div>' for lbl in ["P", "W", "D", "L", "GF", "GA", "GD"])
            + '<div class="wc-lbl-stat wc-lbl-pts">Pts</div></div>'
            + "".join(rows)
            + "</div>"
        )

    ko_cols_html = []
    for col_key, col in ko_columns.items():
        matches = col["matches"]
        count = len(matches)
        slots = (
            '<div class="wc-ko-empty">—</div>'
            if not matches
            else "".join(
                f'<div class="wc-ko-slot" style="{ko_slot_grid_style(i, count, ko_leaf_rows)}">{render_ko_card(m)}</div>'
                for i, m in enumerate(matches)
            )
        )
        ko_cols_html.append(
            f'<div class="wc-ko-col wc-ko-col-{col_key}">'
            f'<div class="wc-ko-col-title">{html.escape(col["label"])}</div>'
            f'<div class="wc-ko-slots">{slots}</div></div>'
        )

    note_html = f'<p class="preview-note">{html.escape(ko_note)}</p>' if ko_note else ""

    css = Path(__file__).with_name("_preview_css.txt")
    # inline minimal CSS copied from Table_Standing_Fifa.txt
    styles = f"""
    body{{margin:0;padding:24px;background:#f5f5f5;font-family:Helvetica,Arial,sans-serif}}
    .preview-wrap{{max-width:1200px;margin:0 auto;background:#fff;padding:24px;border-radius:8px}}
    .preview-note{{margin:0 0 16px;padding:10px 12px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;font-size:13px;color:#5d4037}}
    #{uid}{{box-sizing:border-box;width:100%;max-width:100%;color:#000;background:#fff;line-height:1.2}}
    #{uid} *{{box-sizing:border-box}}
    #{uid} .wc-tabs{{display:flex;gap:0;width:100%;margin:0 0 24px;border-top:1px solid #e3e3e3;border-bottom:1px solid #e3e3e3}}
    #{uid} .wc-tab{{flex:1;padding:14px 12px;border:none;background:transparent;font:inherit;font-size:15px;font-weight:600;color:#555;cursor:pointer;position:relative}}
    #{uid} .wc-tab.is-active{{color:#111;font-weight:700}}
    #{uid} .wc-tab.is-active::after{{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:#111}}
    #{uid} .wc-tab-panel{{display:none !important}}
    #{uid} .wc-tab-panel.is-active{{display:block !important}}
    #{uid} .wc-groups-list{{display:flex;flex-direction:column;gap:40px}}
    #{uid} .wc-group-title{{margin:0 0 14px;font-size:17px;font-weight:700}}
    #{uid} .wc-row{{display:grid;grid-template-columns:minmax(0,1fr) 30px 30px 30px 30px 30px 30px 30px 38px;align-items:center;padding:14px 0;border-bottom:1px solid #e6e6e6}}
    #{uid} .wc-row-head{{padding:0 0 12px}}
    #{uid} .wc-lbl-team{{text-align:left;font-size:14px;color:#9a9a9a}}
    #{uid} .wc-lbl-stat{{text-align:center;font-size:14px;color:#9a9a9a}}
    #{uid} .wc-lbl-pts{{color:#000;font-weight:700}}
    #{uid} .wc-team-cell{{display:flex;align-items:center;gap:12px;min-width:0}}
    #{uid} .wc-rank{{flex:0 0 14px;font-size:15px}}
    #{uid} .wc-team-cell img{{width:26px;height:17px;object-fit:cover}}
    #{uid} .wc-name{{flex:1;min-width:0;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}
    #{uid} .wc-val-stat,#{{uid}} .wc-val-pts{{text-align:center;font-size:15px}}
    #{uid} .wc-val-pts{{font-weight:700}}
    #{uid} .wc-bracket{{display:flex;gap:0;width:100%;padding:8px 0 16px;position:relative;overflow-x:auto}}
    #{uid} .wc-bracket-svg{{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:1}}
    #{uid} .wc-bracket-svg path{{fill:none;stroke:#c8c8c8;stroke-width:1}}
    #{uid} .wc-ko-col{{flex:1 1 0;min-width:0;display:flex;flex-direction:column;position:relative;z-index:2}}
    #{uid} .wc-ko-col-title{{text-align:center;font-size:14px;font-weight:600;color:#333;margin:0 0 14px;padding:0 8px}}
    #{uid} .wc-ko-slots{{display:grid;grid-template-rows:repeat(var(--ko-rows,16),var(--ko-row-step,84px));align-content:start;padding:0 6px 8px;width:100%;min-height:calc(var(--ko-rows,16) * var(--ko-row-step,84px))}}
    #{uid} .wc-ko-slot{{display:flex;align-items:center;justify-content:center;min-height:var(--ko-row-step,84px);width:100%}}
    #{uid} .wc-ko-card{{background:#ececec;border-radius:8px;padding:8px 10px;width:100%;height:var(--ko-card-h,72px);display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}}
    #{uid} .wc-ko-meta{{display:flex;justify-content:space-between;font-size:11px;color:#666}}
    #{uid} .wc-ko-team{{display:flex;align-items:center;gap:6px;font-size:12px;line-height:22px;color:#111}}
    #{uid} .wc-ko-team img,#{{uid}} .wc-ko-shield{{width:18px;height:18px;object-fit:contain;flex:0 0 18px}}
    #{uid} .wc-ko-shield{{display:inline-flex;align-items:center;justify-content:center;line-height:0}}
    #{uid} .wc-ko-shield svg{{width:14px;height:17px;display:block}}
    #{uid} .wc-ko-team-name{{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}
    #{uid} .wc-ko-arrow{{width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:5px solid transparent;flex:0 0 5px}}
    #{uid} .wc-ko-team.is-winner .wc-ko-arrow{{border-left-color:#111}}
    #{uid} .wc-ko-score{{font-weight:600;min-width:28px;text-align:right}}
    #{uid} .wc-ko-empty{{text-align:center;font-size:12px;color:#999;padding:24px 8px}}
    """

    js = """
    (function(){
      var root=document.getElementById('wc_standings_preview');
      var tabs=root.querySelectorAll('.wc-tab');
      var panels={group:root.querySelector('.wc-tab-panel-group'),knockout:root.querySelector('.wc-tab-panel-knockout')};
      function drawKoLines(){
        var bracket=root.querySelector('.wc-bracket');
        var svg=root.querySelector('.wc-bracket-svg');
        if(!bracket||!svg)return;
        var cols=bracket.querySelectorAll('.wc-ko-col');
        var box=bracket.getBoundingClientRect();
        var w=Math.max(bracket.offsetWidth,1), h=Math.max(bracket.offsetHeight,1);
        svg.setAttribute('width',w); svg.setAttribute('height',h);
        svg.setAttribute('viewBox','0 0 '+w+' '+h); svg.innerHTML='';
        function midY(el){var r=el.getBoundingClientRect();return (r.top+r.bottom)/2-box.top;}
        function rightX(el){return el.getBoundingClientRect().right-box.left;}
        function leftX(el){return el.getBoundingClientRect().left-box.left;}
        for(var i=0;i<cols.length-1;i++){
          var leftCards=cols[i].querySelectorAll('.wc-ko-card');
          var rightCards=cols[i+1].querySelectorAll('.wc-ko-card');
          if(leftCards.length<2||!rightCards.length)continue;
          var leftCol=cols[i].getBoundingClientRect(), rightCol=cols[i+1].getBoundingClientRect();
          var gapMid=((leftCol.right-box.left)+(rightCol.left-box.left))/2;
          for(var j=0;j<rightCards.length;j++){
            var top=leftCards[j*2], bot=leftCards[j*2+1], dest=rightCards[j];
            if(!top||!bot||!dest)continue;
            var y1=midY(top), y2=midY(bot), yMid=(y1+y2)/2, yDest=midY(dest);
            var xOut=rightX(top), xIn=leftX(dest);
            var d='M'+xOut+','+y1+'H'+gapMid+'M'+xOut+','+y2+'H'+gapMid+'M'+gapMid+','+y1+'V'+y2+'M'+gapMid+','+yMid+'H'+xIn+'V'+yDest;
            var path=document.createElementNS('http://www.w3.org/2000/svg','path');
            path.setAttribute('d',d); svg.appendChild(path);
          }
        }
      }
      function onKoVisible(){requestAnimationFrame(function(){drawKoLines();setTimeout(drawKoLines,80);});}
      tabs.forEach(function(tab){
        tab.addEventListener('click',function(){
          var key=tab.getAttribute('data-wc-tab');
          tabs.forEach(function(t){var a=t===tab;t.classList.toggle('is-active',a);t.setAttribute('aria-selected',a?'true':'false');});
          Object.keys(panels).forEach(function(k){panels[k].classList.toggle('is-active',k===key);});
          if(key==='knockout')onKoVisible();
        });
      });
      window.addEventListener('resize',function(){if(panels.knockout&&panels.knockout.classList.contains('is-active'))drawKoLines();});
    })();
    """

    out = f"""<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FIFA WC 2026 Standings Preview</title><style>{styles}</style></head><body>
<div class="preview-wrap">
<h1 style="margin:0 0 8px;font-size:22px">FIFA World Cup 2026 — Standings Preview</h1>
<p style="margin:0 0 20px;color:#666;font-size:14px">Live API data · Groups: 2026 · Generated {datetime.now().strftime("%Y-%m-%d %H:%M")}</p>
{note_html}
<div id="{uid}" class="wc-standings-root">
<div class="wc-tabs" role="tablist">
<button type="button" class="wc-tab is-active" data-wc-tab="group" aria-selected="true">Group Stage</button>
<button type="button" class="wc-tab" data-wc-tab="knockout" aria-selected="false">Knockout Stage</button>
</div>
<div class="wc-tab-panel wc-tab-panel-group is-active"><div class="wc-groups-list">{"".join(group_html)}</div></div>
<div class="wc-tab-panel wc-tab-panel-knockout">
<div class="wc-knockout"><div class="wc-bracket" style="--ko-rows:{ko_leaf_rows};--ko-card-h:72px;--ko-row-step:84px">
<svg class="wc-bracket-svg" aria-hidden="true"></svg>
{"".join(ko_cols_html)}
</div></div></div>
</div></div>
<script>{js}</script></body></html>"""

    out_path = Path(__file__).with_name("preview_fifa_standings.html")
    out_path.write_text(out, encoding="utf-8")
    print(out_path)


if __name__ == "__main__":
    main()
