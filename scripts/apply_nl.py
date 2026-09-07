#!/usr/bin/env python3
"""Nest translatable strings as {en, nl} objects on lesson JSON files."""
from __future__ import annotations

import json
from pathlib import Path

DIFF = {
    "Introductory": "Instap",
    "Beginner": "Beginner",
    "Intermediate": "Gevorderd",
    "All": "Alle niveaus",
}

PATHS = {
    "Learn To Play The Drums": "Leer drums spelen",
    "Add Variety To Your Drumming": "Breng variatie in je drummen",
    "Essentials For Popular Music": "Essentials voor populaire muziek",
    "Play Faster With Confidence": "Speel sneller met vertrouwen",
    "Expand Your Groove And Feel": "Verbreed je groove en feel",
    "Develop & Coordinate Your Groove": "Ontwikkel en coördineer je groove",
    "Make Your Drumming Musical": "Maak je drummen muzikaal",
    "Modern Rock & Pop Drumming": "Modern rock- en popdrummen",
    "Groove Variety & Vocabulary": "Groove-variatie en vocabulaire",
}

PATH_DESC = {
    "Learn basic beats and fills — play easy songs as quickly as possible!":
        "Leer basisbeats en fills — speel zo snel mogelijk eenvoudige nummers!",
}

PACKS = {
    "16th-Note Bass Drum Patterns": "16de-noot basdrumpatronen",
    "16th-Note Drum Fills": "16de-noot drumfills",
    "16th-Note Grooves": "16de-noot grooves",
    "16th-Note Rhythm Variations": "16de-noot ritmevariaties",
    "6/8 And 12/8 Fills": "Fills in 6/8 en 12/8",
    "6/8 And 12/8 Grooves": "Grooves in 6/8 en 12/8",
    "8th-Note Bass Drum Patterns": "8ste-noot basdrumpatronen",
    "8th-Note Drum Fills": "8ste-noot drumfills",
    "8th-Note Grooves Around The Kit": "8ste-noot grooves over het drumstel",
    "Accented Snare Grooves": "Geaccentueerde snaregrooves",
    "Accents And Taps": "Accenten en taps",
    "Adding Accents To Fills": "Accenten in fills",
    "Adding Crash Cymbals": "Crashbekkens toevoegen",
    "Adding Weak Hand Accents To Fills": "Zwakke-handaccenten in fills",
    "Backbeat Variations": "Backbeat-variaties",
    "Changing Time Feels": "Time feel wisselen",
    "Counting Bars & Phrases": "Maten en frasen tellen",
    "Crash Cymbal Accents": "Crashbekken-accenten",
    "Crash Cymbal Fills": "Crashbekken-fills",
    "Cymbal Grooves With Both Hands": "Bekkengrooves met beide handen",
    "Cymbal Sound Variations": "Bekkenklank-variaties",
    "Develop Speed And Control": "Snelheid en controle ontwikkelen",
    "Developing Ghost Notes": "Ghost notes ontwikkelen",
    "Developing Timing": "Timing ontwikkelen",
    "Double Time Grooves": "Double-time grooves",
    "Doubles On The Bass Drum": "Doubles op de basdrum",
    "Drum-Fill Timing": "Timing van drumfills",
    "Four On The Floor": "Four on the floor",
    "Four On The Floor Variations": "Four-on-the-floor-variaties",
    "Getting Started With Single Strokes": "Aan de slag met single strokes",
    "Intro To Double Strokes": "Intro tot double strokes",
    "Intro To Paradiddles": "Intro tot paradiddles",
    "Introduction To Cross-Stick": "Intro tot cross-stick",
    "Introduction To Ghost Notes": "Intro tot ghost notes",
    "Keeping Time with The Hi-Hat Foot": "Tijd houden met de hi-hatvoet",
    "Lead-Hand Independence": "Onafhankelijkheid van de leidende hand",
    "Lead-Hand Speed And Endurance": "Snelheid en uithouding van de leidende hand",
    'Learn "Between The Lines"': 'Leer "Between The Lines"',
    'Learn "Chasing Rolling Thunder"': 'Leer "Chasing Rolling Thunder"',
    'Learn "Lost In Transmission"': 'Leer "Lost In Transmission"',
    'Learn "One More Mile"': 'Leer "One More Mile"',
    'Learn "Phase Shift"': 'Leer "Phase Shift"',
    'Learn "Something To Have"': 'Leer "Something To Have"',
    'Learn "Three Leaves"': 'Leer "Three Leaves"',
    'Learn "Waves Of Grey"': 'Leer "Waves Of Grey"',
    'Learn "Wild & Cold"': 'Leer "Wild & Cold"',
    "Leaving Gaps In Your Groove": "Gaten in je groove",
    "More Fills In 6/8 & 12/8": "Meer fills in 6/8 en 12/8",
    "More Grooves in 6/8 & 12/8": "Meer grooves in 6/8 en 12/8",
    "Opening The Hi-Hat": "De hi-hat openen",
    "Playing Rimshots": "Rimshots spelen",
    "Playing The Hi-Hat Pedal": "De hi-hatpedaal spelen",
    "Playing With Dynamics": "Spelen met dynamiek",
    "Pop Fills": "Popfills",
    "Reading Sheet Music": "Bladmuziek lezen",
    "Ride Bell Accents": "Ride-bell-accenten",
    "Shank Tip On The Hi-Hat": "Shank-tip op de hi-hat",
    "Snare And Kick Coordination": "Snare- en kickcoördinatie",
    "Snare Drum Variations": "Snarevariaties",
    "The Flam": "De flam",
    "The Money Beat": "De Money Beat",
    "The Quarter-Note Groove": "De kwartnoot-groove",
    "The Quarter-Note Groove Around The Kit": "De kwartnoot-groove over het drumstel",
    "Welcome": "Welkom",
}

LESSONS = {
    "16th Note Train Grooves": "16de-noot treingrooves",
    "16th Notes": "16de noten",
    "16ths With One Hand": "16den met één hand",
    "4-Way Coordination": "4-wegcoördinatie",
    "8th-Note Variations": "8ste-nootvariaties",
    "8ths On The Ride Cymbal": "8sten op de ride",
    "8ths-To-16ths Transitions": "Overgang van 8sten naar 16den",
    "A Crash To Top It Off": "Een crash als afsluiter",
    "A Foundational Groove": "Een fundament-groove",
    "A Kick After The Backbeat": "Een kick na de backbeat",
    "A Kick Before The Backbeat": "Een kick voor de backbeat",
    "A New Variation": "Een nieuwe variatie",
    "A Sixteenth Note Fill": "Een 16de-nootfill",
    "A Toolbox Of Fills": "Een gereedschapskist vol fills",
    "Accents On The Toms": "Accenten op de toms",
    "Add A Buildup": "Voeg een opbouw toe",
    "Add An Extra Kick": "Voeg een extra kick toe",
    "Add More Accents": "Meer accenten",
    "Add The Bell": "De bell erbij",
    "Add The Kick": "De kick erbij",
    "Adding 8th Notes": "8ste noten toevoegen",
    "Adding A Crash": "Een crash toevoegen",
    "Adding Crashes": "Crashes toevoegen",
    "Adding More Ghost Notes": "Meer ghost notes",
    "Adding More Kick": "Meer kick",
    "Adding More Shanks": "Meer shanks",
    "Adding Rests": "Pauzes toevoegen",
    "Adding Sixteenths": "16den toevoegen",
    "Adding The Kick": "De kick toevoegen",
    "Adding The Ride": "De ride toevoegen",
    "All Around The Kit": "Over het hele drumstel",
    "An Accent Challenge": "Een accentuitdaging",
    "An Eighth Note Fill": "Een 8ste-nootfill",
    "An Extra Bass Drum Note": "Een extra basdrumnoot",
    "An Iconic Drum Fill": "Een iconische drumfill",
    "An Iconic Groove": "Een iconische groove",
    "An Iconic Snare Groove": "Een iconische snaregroove",
    "Another New Fill": "Nog een nieuwe fill",
    "Applying The Accents": "De accenten toepassen",
    "Around The Kit": "Over het drumstel",
    "Backbeats": "Backbeats",
    "Bass Drum Creativity": "Creativiteit op de basdrum",
    "Being Subtle": "Subtiel blijven",
    "Boom, Boom, Bap!": "Boom, boom, bap!",
    "Break Free From The Hi-Hat": "Los van de hi-hat",
    "Bring In A Backbeat": "Een backbeat erin",
    "Bring In A Crash": "Een crash erin",
    "Bring In A Fill": "Een fill erin",
    "Bring In The Groove": "De groove erin",
    "Bring In The Kick": "De kick erin",
    "Bring In The Toms": "De toms erin",
    "Building Coordination": "Coördinatie opbouwen",
    "Change It To 16ths": "Verander het naar 16den",
    "Change Up Beat 4": "Beat 4 variëren",
    "Change Up The Kick Drum": "De kick variëren",
    "Change Up The Snare Drum": "De snare variëren",
    "Change Up Your Lead Hand": "Je leidende hand wisselen",
    "Changing Everything At Once": "Alles tegelijk veranderen",
    "Changing Sections": "Secties wisselen",
    "Changing Up The Feel": "De feel veranderen",
    "Changing Up The Kick": "De kick veranderen",
    "Changing Up The Snare Drum": "De snare veranderen",
    "Combination Practice": "Combinatie oefenen",
    "Combine The Patterns": "De patronen combineren",
    "Combine The Three": "De drie combineren",
    "Combine The Two": "De twee combineren",
    "Combine Them All": "Alles combineren",
    "Combining The Two": "De twee combineren",
    "Coordinate The Left Foot": "De linkervoet coördineren",
    "Coordinating Our Hands": "Onze handen coördineren",
    "Coordination Around The Kit": "Coördinatie over het drumstel",
    "Counting Full Bars": "Hele maten tellen",
    "Counting Full Sections": "Hele secties tellen",
    "Counting Quarters": "Kwartnoten tellen",
    "Crashes All Over The Place": "Crashes overal",
    "Crashing From The Floor Tom": "Crashen vanaf de floor tom",
    "Crashing On Beat 2": "Crashen op beat 2",
    "Crashing On Beat 4": "Crashen op beat 4",
    "Crashing On The \"And\"": "Crashen op de 'en'",
    "Crashing On Transitions": "Crashen op overgangen",
    "Crashing Through The 16ths": "Crashen door de 16den",
    "Cymbal Sounds Around The Kit": "Bekkenklanken over het drumstel",
    "Developing Vocab": "Vocabulaire ontwikkelen",
    "Different Patterns": "Andere patronen",
    "Double And Back": "Double en terug",
    "Double Into Beat 3": "Double naar beat 3",
    "Double It Up": "Verdubbelen",
    "Double On Beat 1": "Double op beat 1",
    "Double Up On The Fill": "De fill verdubbelen",
    "Double Up Those 16ths": "Die 16den verdubbelen",
    "Dynamic Choices": "Dynamische keuzes",
    "Ease Into It": "Rustig opbouwen",
    "Embellished Offbeats": "Versierde offbeats",
    "Embellishing The Groove": "De groove versieren",
    "Even More Accents": "Nog meer accenten",
    "Even More Kick Drum!": "Nog meer kick!",
    "Expand Your Drum-Set Sounds": "Meer klanken op het drumstel",
    "Expand Your Groove Options": "Meer groove-opties",
    "Fill Combination": "Fillcombinatie",
    "Fill For The Song": "Fill voor het nummer",
    "Fill Transitions": "Fill-overgangen",
    "Filling For 2 Beats": "Fills van 2 beats",
    "Filling Up The Bar": "De maat vullen",
    "Final Performance": "Einduitvoering",
    "Find Your Balance": "Vind je balans",
    "Finding Your Comfort Zone": "Je comfortzone vinden",
    "Flam Tom Fills": "Flam-tomfills",
    "Flams Around The Kit": "Flams over het drumstel",
    "Four On The Floor": "Four on the floor",
    "Four-On-The-Floor Creativity": "Four-on-the-floor-creativiteit",
    "Four-On-The-Floor Transitions": "Four-on-the-floor-overgangen",
    "Further Practice": "Verder oefenen",
    "Get Comfortable With Crashing": "Comfortabel crashen",
    "Getting Comfortable": "Comfortabel worden",
    "Getting Comfortable With Rimshots": "Comfortabel met rimshots",
    "Getting Comfortable With Switching": "Comfortabel wisselen",
    "Getting Comfortable With Them All": "Comfortabel met allemaal",
    "Getting Comfy With Coordination": "Comfortabel met coördinatie",
    "Getting Even Faster": "Nog sneller",
    "Getting Faster": "Sneller worden",
    "Getting More Comfortable": "Nog comfortabeler",
    "Getting More Reps In": "Meer herhalingen",
    "Getting The Coordination": "De coördinatie te pakken krijgen",
    "Going Clockwise": "Met de klok mee",
    "Going Further With 12/8": "Verder met 12/8",
    "Grooving On The Ride": "Grooven op de ride",
    "Groupings Of Four": "Groepen van vier",
    "Guided Play Through": "Begeleide playthrough",
    "Guided Playthrough": "Begeleide playthrough",
    "Hi-Hat Transitions": "Hi-hatovergangen",
    "How To Approach Fills": "Hoe je fills aanpakt",
    "Incorporate The Toms": "De toms erin verwerken",
    "Incorporating Ghost Notes": "Ghost notes verwerken",
    "Increase Your Speed": "Verhoog je snelheid",
    "Internalize Your Counting": "Je telling internaliseren",
    "Learn A New Fill": "Leer een nieuwe fill",
    "Learn The Bridge": "Leer de bridge",
    "Learn The Chorus": "Leer het refrein",
    "Learn The Choruses": "Leer de refreinen",
    "Learn The Fill": "Leer de fill",
    "Learn The Fills": "Leer de fills",
    "Learn The Grooves": "Leer de grooves",
    "Learn The Verse": "Leer het couplet",
    "Learn The Verse ": "Leer het couplet",
    "Leaving Out Even More Notes": "Nog meer noten weglaten",
    "Leaving Out Notes": "Noten weglaten",
    "Listen With Intention": "Luister met intentie",
    "Listening & Timing": "Luisteren en timing",
    "Lock In With Crashes": "Vastzetten met crashes",
    "Mixing It Up": "Afwisselen",
    "Move It Around The Kit": "Verplaats het over het drumstel",
    "Moving Around The Kit": "Over het drumstel bewegen",
    "Moving The Kick": "De kick verplaatsen",
    "Moving The Left Hand": "De linkerhand verplaatsen",
    "Moving To The Ride": "Naar de ride",
    "Normal To Double And Back": "Normaal naar double en terug",
    "Now We're Cookin'": "Nu zijn we bezig",
    "Offbeat Accents": "Offbeat-accenten",
    "Offbeats": "Offbeats",
    "Opening On A Different Beat": "Openen op een andere beat",
    "Orchestrate Your Beats": "Orkestreer je beats",
    "Own The Money Beat": "Eigenaar van de Money Beat",
    "Paradiddle Fills Around The Kit": "Paradiddle-fills over het drumstel",
    "Pat Boone, Debby Boone": "Pat Boone, Debby Boone",
    "Patterns Around The Kit": "Patronen over het drumstel",
    "Picking Up The Tempo": "Het tempo optrekken",
    "Play It Twice": "Speel het twee keer",
    "Playing Around The Kit": "Spelen over het drumstel",
    "Playing Both Patterns": "Beide patronen spelen",
    "Playing Flams On The Toms": "Flams op de toms",
    "Playing For Two Beats": "Twee beats spelen",
    "Playing From The Ride": "Vanaf de ride spelen",
    "Put It All Together": "Alles samenbrengen",
    "Putting It All Together": "Alles samenbrengen",
    "Quarter And 8th Notes": "Kwart- en 8ste noten",
    "Quarter Note Accents": "Kwartnootaccenten",
    "Removing Some Steps": "Stappen weglaten",
    "Review": "Herhaling",
    "Review & Challenge": "Herhaling en uitdaging",
    "Review The Pattern": "Het patroon herhalen",
    "Review The Patterns": "De patronen herhalen",
    "Rimshots Along With The Toms": "Rimshots samen met de toms",
    "Rimshots In Grooves": "Rimshots in grooves",
    "Separate Your Hands": "Scheid je handen",
    "Single Stroke Combinations": "Single-strokecombinaties",
    "Singles Around The Drums": "Singles over de drums",
    "Skip The Steps": "Sla de stappen over",
    "Snare Embellishments": "Snareversieringen",
    "Snare Quarters": "Snare-kwartnoten",
    "Solidify The Groove": "De groove vastzetten",
    "Solidify The Pattern": "Het patroon vastzetten",
    "Speeding It Up": "Versnellen",
    "Speeding Up Your Doubles": "Je doubles versnellen",
    "Splitting It Up": "Opsplitsen",
    "Starting On Beat 1": "Starten op beat 1",
    "Starting On Beat 2": "Starten op beat 2",
    "Starting On Beat 3": "Starten op beat 3",
    "Starting On Beat 4": "Starten op beat 4",
    "Starting With 8th Notes": "Beginnen met 8ste noten",
    "Staying Relaxed": "Ontspannen blijven",
    "Stop On 4": "Stop op 4",
    "Switching With Our Snare Hand": "Wisselen met de snarehand",
    "Syncopated Grooves": "Gesyncopeerde grooves",
    "The 3-3-2 Pattern": "Het 3-3-2-patroon",
    "The 4-&Ah Fill": "De 4-&ah-fill",
    "The 4e&- Fill": "De 4e&-fill",
    "The 6/8 Groove": "De 6/8-groove",
    "The 8th-Note Fill": "De 8ste-nootfill",
    "The 8th-Note Groove": "De 8ste-nootgroove",
    "The And-Ah": "De en-ah",
    "The Bridge": "De bridge",
    "The Chorus": "Het refrein",
    "The Down & Up Strokes": "Down- en upstrokes",
    "The First Verse And Chorus": "Het eerste couplet en refrein",
    "The Full & Tap Strokes": "Full- en tapstrokes",
    "The Full Pattern": "Het volledige patroon",
    "The Full-Bar Fill": "De hele-maat-fill",
    "The Funky Kick": "De funky kick",
    "The Gap Challenge": "De gat-uitdaging",
    "The Groove That Pays": "De groove die loont",
    "The Hi-Hat And Snare Drum": "De hi-hat en snare",
    "The Iconic 4OTF Groove": "De iconische 4OTF-groove",
    "The Iconic Rudiment": "Het iconische rudiment",
    "The Low-End Foundation": "Het low-end fundament",
    "The Money Beat": "De Money Beat",
    "The Motown": "De Motown",
    "The Ride And Floor Tom": "De ride en floor tom",
    "The Ride Bell": "De ride-bell",
    "The Ride Bell Groove": "De ride-bell-groove",
    "The Second Verse And Chorus": "Het tweede couplet en refrein",
    "The Secret To Playing Fast": "Het geheim van snel spelen",
    "The Sloshy Hi-Hat": "De sloshy hi-hat",
    "The Verse": "Het couplet",
    "The Wrist Motion": "De polsbeweging",
    "Thinking Ahead": "Vooruitdenken",
    "Time Feel": "Time feel",
    "Timing Combinations": "Timingcombinaties",
    "Train Grooves": "Treingrooves",
    "Transitioning Around The Kit": "Overgangen over het drumstel",
    "Transitioning Sections": "Secties overbruggen",
    "Transitioning With A Fill": "Overgaan met een fill",
    "Two Different Fills": "Twee verschillende fills",
    "Two Funky Kicks": "Twee funky kicks",
    "Two-Handed 16th Grooves": "Tweehandige 16de-grooves",
    "Unlock The Most Iconic Groove": "De meest iconische groove ontgrendelen",
    "Unlocking Our Lead Hand": "Onze leidende hand ontgrendelen",
    "Using Cross-Stick": "Cross-stick gebruiken",
    "Using The Hi-Hat Pedal": "De hi-hatpedaal gebruiken",
    "Variations As Fills": "Variaties als fills",
    "Vary The Groove": "Varieer de groove",
    "Vary The Pattern": "Varieer het patroon",
    "Welcome To Add Variety To Your Drumming": "Welkom bij Breng variatie in je drummen",
    "Welcome To Develop & Coordinate Your Groove": "Welkom bij Ontwikkel en coördineer je groove",
    "Welcome To Essentials For Popular Music": "Welkom bij Essentials voor populaire muziek",
    "Welcome To Expand Your Groove And Feel": "Welkom bij Verbreed je groove en feel",
    "Welcome To Groove Variety & Vocabulary": "Welkom bij Groove-variatie en vocabulaire",
    "Welcome To Learn To Play The Drums": "Welkom bij Leer drums spelen",
    "Welcome To Make Your Drumming Musical": "Welkom bij Maak je drummen muzikaal",
    "Welcome To Modern Rock & Pop Drumming": "Welkom bij Modern rock- en popdrummen",
    "Welcome To Play Faster With Confidence": "Welkom bij Speel sneller met vertrouwen",
    "Welcome To The Drumeo Method": "Welkom bij The Drumeo Method",
    "What Are 16th Notes?": "Wat zijn 16de noten?",
    "What Are Double Strokes?": "Wat zijn double strokes?",
    "What Are Ghost Notes?": "Wat zijn ghost notes?",
    "What Are Rimshots?": "Wat zijn rimshots?",
    "What Are Single Strokes?": "Wat zijn single strokes?",
    "What Is 12/8?": "Wat is 12/8?",
    "What Is 6/8?": "Wat is 6/8?",
    "What Is A Flam?": "Wat is een flam?",
    "What Is Cross-Stick?": "Wat is cross-stick?",
    "What Is Double Time?": "Wat is double time?",
    "What Is Four On The Floor?": "Wat is four on the floor?",
    "What Is Shank-Tip?": "Wat is shank-tip?",
    "What Is The Money Beat?": "Wat is de Money Beat?",
}


def nl_title(en: str | None) -> str | None:
    if not en:
        return None
    if en in LESSONS:
        return LESSONS[en]
    if en in PACKS:
        return PACKS[en]
    if en in PATHS:
        return PATHS[en]
    return None


def loc_en(val) -> str | None:
    if isinstance(val, dict) and ("en" in val or "nl" in val):
        en = val.get("en")
        return en if isinstance(en, str) else None
    if isinstance(val, str) and val:
        return val
    return None


def set_nl(obj: dict, key: str, table: dict | None = None) -> None:
    table = table or {}
    cur = obj.get(key)
    en = loc_en(cur)
    if not en:
        return
    existing_nl = None
    if isinstance(cur, dict):
        n = cur.get("nl")
        existing_nl = n if isinstance(n, str) and n else None
    elif isinstance(obj.get(f"{key}_nl"), str):
        existing_nl = obj[f"{key}_nl"]
    val = table.get(en) or nl_title(en) or existing_nl
    loc = {"en": en}
    if val:
        loc["nl"] = val
    obj[key] = loc
    obj.pop(f"{key}_nl", None)


def walk(obj):
    if isinstance(obj, dict):
        if "title" in obj:
            set_nl(obj, "title")
        if "difficulty_string" in obj:
            set_nl(obj, "difficulty_string", DIFF)
        desc = obj.get("description")
        if loc_en(desc) or isinstance(obj.get("description_nl"), str):
            set_nl(obj, "description", PATH_DESC)
        for v in obj.values():
            walk(v)
    elif isinstance(obj, list):
        for v in obj:
            walk(v)


def main() -> None:
    roots = [Path("lessons"), Path("lesson-jsons")]
    missing: set[str] = set()
    n = 0
    for root in roots:
        if not root.exists():
            continue
        for p in sorted(root.rglob("*.json")):
            if p.name.startswith("i18n"):
                continue
            data = json.loads(p.read_text())
            walk(data)
            p.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n")
            n += 1
            # collect missing titles
            def collect(o):
                if isinstance(o, dict):
                    t = o.get("title")
                    en = loc_en(t)
                    has_nl = isinstance(t, dict) and isinstance(t.get("nl"), str) and t.get("nl")
                    if en and not has_nl:
                        missing.add(en)
                    for v in o.values():
                        collect(v)
                elif isinstance(o, list):
                    for v in o:
                        collect(v)
            collect(data)
    print(f"updated {n} files")
    if missing:
        print("MISSING", len(missing))
        for t in sorted(missing):
            print(" ", t)
    else:
        print("all titles translated")


if __name__ == "__main__":
    main()
