def harrypotter():
    input_block = """
18,956	Harry Potter
6,464	Ron Weasley
5,486	Hermione Granger
2,421	Albus Dumbledore
2,024	Rubeus Hagrid
1,956	Severus Snape
1,797	Voldemort
1,471	Sirius Black
1,198	Draco Malfoy
920	Fred Weasley
864	Remus Lupin
821	George Weasley
810	Neville Longbottom
780	Arthur Weasley
771	Ginny Weasley
770	Minerva McGonagall
722	Molly Weasley
637	Dolores Umbridge
583	Alastor 'Mad-Eye' Moody
530	Vernon Dursley
493	Cornelius Fudge
486	Peter Pettigrew
469	Dobby
467	Dudley Dursley
432	Horace Slughorn
426	Percy Weasley
356	Luna Lovegood
353	Cedric Diggory
314	Petunia Dursley
312	Kreacher
302	Bill Weasley
295	Barty Crouch Sr.
288	Argus Filch
277	Viktor Krum
262	Gilderoy Lockhart
262	Sybill Trelawney
258	Fleur Delacour
249	Lucius Malfoy
246	Ludo Bagman
242	Nymphadora Tonks
228	Gregory Goyle
224	Vincent Crabbe
220	Bellatrix Lestrange
215	Cho Chang
212	Dean Thomas
208	Oliver Wood
204	Hedwig
190	James Potter
188	Rita Skeeter
179	Seamus Finnigan
165	Igor Karkaroff
164	Peeves
162	Winky
162	Crookshanks
156	Poppy Pomfrey
153	Rufus Scrimgeour
147	Mundungus Fletcher
141	Lavender Brown
140	Griphook
140	Filius Flitwick
136	Buckbeak
135	Angelina Johnson
133	Parvati Patil
127	Lily Potter
124	Xenophilius Lovegood
123	Nearly Headless Nick
122	Quirinus Quirrell
119	Moaning Myrtle
117	Garrick Ollivander
116	Katie Bell
112	Olympe Maxime
110	Charlie Weasley
109	Lee Jordan
106	Kingsley Shacklebolt
105	Fang
102	Fenrir Greyback
92	Ernie Macmillan
90	Pomona Sprout
89	Phineas Nigellus Black
89	Narcissa Malfoy
85	Stan Shunpike
79	Aberforth Dumbledore
77	Bathilda Bagshot
77	Colin Creevey
76	Amos Diggory
75	Firenze
75	Grawp
73	Marge Dursley
73	Cormac McLaggen
73	Gellert Grindelwald
72	Fat Lady
70	Salazar Slytherin
68	Bob Ogden
68	Pansy Parkinson
68	Marvolo Gaunt
64	Fawkes
63	Frank Bryce
63	Morfin Gaunt
63	Godric Gryffindor
62	Elphias Doge
60	Pigwidgeon
59	Mrs. Norris
58	Alicia Spinnet
57	Wilhelmina Grubbly-Plank
56	Aunt Muriel
56	Yaxley
55	Arabella Figg
54	Regulus Black
53	Rolanda Hooch
51	Mr. Borgin
50	Justin Finch-Fletchley
50	Ariana Dumbledore
49	Madam Rosmerta
49	Gregorovitch
49	Aragog
48	Marietta Edgecombe
48	Cuthbert Binns
48	Zacharias Smith
47	Blaise Zabini
42	Bertha Jorkins
41	Travers
40	Marcus Flint
39	Ted Tonks
36	Barty Crouch Jr.
36	Norbert
35	Amelia Bones
34	Ernie Prang
34	Antonin Dolohov
33	Montague
32	Merope Gaunt
31	Nicolas Flamel
31	Mary Cattermole
30	Roger Davies
30	Bane
30	Nagini
28	Rowena Ravenclaw
27	Fluffy
27	Dedalus Diggle
27	Hepzibah Smith
27	Walden Macnair
27	Albus Severus Potter
26	Errol
26	Broderick Bode
26	Michael Corner
26	Hannah Abbott
25	Madam Malkin
25	Augustus Rookwood
24	Tom
24	Bloody Baron
23	Sturgis Podmore
23	Reg Cattermole
23	Dirk Cresswell
23	Padma Patil
23	Amycus Carrow
23	Sir Cadogan
23	Ronan
22	Warrington
22	Kendra Dumbledore
21	Mrs. Cole
21	Armando Dippet
20	Mr. Roberts
20	Leanne
20	Pius Thicknesse
19	Hokey
19	Irma Pince
19	Albert Runcorn
19	Dawlish
18	Griselda Marchbanks
18	Trevor
18	Tom Riddle Sr.
18	Romilda Vane
18	Magorian
17	Dennis Creevey
17	Millicent Bulstrode
17	Beedle the Bard
17	Scabior
16	Aidan Lynch
16	Moran
16	Piers Polkiss
16	Demelza Robins
16	Hestia Jones
16	Mafalda Hopkirk
16	Alecto Carrow
15	Mrs. Black
15	Gabrielle Delacour
15	Bogrod
14	Marcus Belby
14	Terry Boot
14	Helga Hufflepuff
14	James Sirius Potter
13	Hassan Mostafa
13	Adrian Pucey
13	Karkus
13	Professor Tofty
13	Wilkie Twycross
13	Caractacus Burke
13	Jimmy Peakes
13	Avery
12	Troy
12	Augusta Longbottom
    """

    lines = input_block.strip().split('\n')
    for line in lines:
        parts = line.strip().split(None, 1)
        if len(parts) == 2:
            print(parts[1])


def pokemon():
    pokemen = "Bisasam,Bisaknosp,Bisaflor,Glumanda,Glutexo,Glurak,Schiggy,Schillok,Turtok,Raupy,Safcon,Smettbo,Hornliu,Kokuna,Bibor,Taubsi,Tauboga,Tauboss,Rattfratz,Rattikarl,Habitak,Ibitak,Rettan,Arbok,Pikachu,Raichu,Sandan,Sandamer,Nidoran,Nidorina,Nidoqueen,Nidoran,Nidorino,Nidoking,Piepi,Pixi,Vulpix,Vulnona,Pummeluff,Knuddeluff,Zubat,Golbat,Myrapla,Duflor,Giflor,Paras,Parasek,Bluzuk,Omot,Digda,Digdri,Mauzi,Snobilikat,Enton,Entoron,Menki,Rasaff,Fukano,Arkani,Quapsel,Quaputzi,Quappo,Abra,Kadabra,Simsala,Machollo,Maschock,Machomei,Knofensa,Ultrigaria,Sarzenia,Tentacha,Tentoxa,Kleinstein,Georok,Geowaz,Ponita,Gallopa,Flegmon,Lahmus,Magnetilo,Magneton,Porenta,Dodu,Dodri,Jurob,Jugong,Sleima,Sleimok,Muschas,Austos,Nebulak,Alpollo,Gengar,Onix,Traumato,Hypno,Krabby,Kingler,Voltobal,Lektrobal,Owei,Kokowei,Tragosso,Knogga,Kicklee,Nockchan,Schlurp,Smogon,Smogmog,Rihorn,Rizeros,Chaneira,Tangela,Kangama,Seeper,Seemon,Goldini,Golking,Sterndu,Starmie,Pantimos,Sichlor,Rossana,Elektek,Magmar,Pinsir,Tauros,Karpador,Garados,Lapras,Ditto,Evoli,Aquana,Blitza,Flamara,Porygon,Amonitas,Amoroso,Kabuto,Kabutos,Aerodactyl,Relaxo,Arktos,Zapdos,Lavados,Dratini,Dragonir,Dragoran,Mewtu,Mew"
    lines = pokemen.strip().split(',')
    print('\n'.join(lines))


#harrypotter()
pokemon()