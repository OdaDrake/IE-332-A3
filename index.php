<?php
session_start(); // must be first

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// When the form is submitted:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Hardcoded login
    if ($username === 'senior' && $password === 'senior123') {

        // Mark user as logged in
        $_SESSION['logged_in'] = true;
        $_SESSION['username']  = $username;

        header("Location: company_table.php");
        exit;

    } else {
        echo "<script>alert('Invalid username or password');</script>";
    }
}
?>

<html>
  <head>
    <title>IE332 Group 8 ERP System</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <style>
    .header {
      background-color: #141414;
      text-align: center;
      position: fixed;
      width: 100%;
      color: whitesmoke;
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
    }

    .dummy {
      width: 2em;
      aspect-ratio: 1;
      margin-right: 1rem;
    }

    .header h1{
      font-weight: normal;
    }

    h2{
      font-weight: normal;
    }

    .headerspacer {
      margin-bottom: 1rem;
      color: clear;
      background-color: clear;
      padding-top: 0.67em;
      padding-bottom: 0.67em;
    }

    body {
      margin: 0;
      font-size: medium;
      font-family: Verdana, Geneva, Tahoma, sans-serif;
      background-color: whitesmoke;
    }
    
    .content {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding-bottom: 4rem;
    }
    
    .photobox {
      width: 100%;
      display: flex;
      flex-direction: row;
      column-gap: 1rem;
      justify-content: space-around;
      padding-top: 1rem;
      padding-bottom: 7rem;
    }

    .photoboxwrapper {
      height: 20rem;
      width: 90%;
      background-color: #3f3f3f;
      border-radius: 16px 8px 16px 8px;
      box-shadow: 0px 4px 3px 1px #0a0a0a;
      text-align: center;
      color:whitesmoke;
    }

    .square {
      aspect-ratio: 1;
      width: 90%;
      background-color: whitesmoke;
      transition: width 0.2s, height 0.2s;
      transition-timing-function: ease-in-out;
      border-radius: 12px 6px 12px 6px;
    }

    .square img {
      width: 100%;
      border-radius: 12px 6px 12px 6px;
    }

    .square:hover {
      width: 100%;
    } 

    .squarewrapper {
      aspect-ratio: 1;
      width: 8rem;
      display: flex;
      flex-direction: column;
      justify-content: space-around;
      align-items: center;
    }

    .card {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: center;
      text-align: center;
      color: whitesmoke;
    }

    .card p {
      margin-bottom: 0;
    }

    underline {
      border-top: 1px solid whitesmoke;
      width: 0;
      transition: width 0.2s;
      transition-timing-function: ease-in;
      display: block;
    }

    .card:hover underline {
      width: 90%;
    }

    .circle {
      position: absolute;
      display: flex;
      flex-direction: column;
      justify-content: center;
      right: 1rem;
      bottom: 3rem;
      border-radius: 50%;
      background-color: whitesmoke;
      aspect-ratio: 1;
      width: 2rem;
      text-align: center;
      padding: .2rem;
      box-shadow: 0 0 3px 0px #0a0a0a;
      align-items: center;
    }

    .circle:hover{
      box-shadow: 0 0 2px 1px #0a0a0a;
    } 

    .circle:hover underline {
      width: 60%;
      border-top: 1px solid black;
    }

    .header img {
      height: 2em;
      filter: invert(95%);
      margin-left: 1rem;
    }

    #logincontainer {
      margin-top: 2rem;
      background-color: #3f3f3f;
      padding: 1rem 3rem;
      border-radius: 16px 8px 16px 8px;
      box-shadow: 0px 4px 3px 1px #0a0a0a;
      text-align: center;
      color:whitesmoke;
      width: 320px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    #logincontainer h1 {
      margin-bottom: 2rem;
      font-weight: 500;
      letter-spacing: 1px;
    }

    #logincontainer h2 {     
      padding-bottom: 1rem; 
    }

    .login-input {
      width: 100%;
      padding: 0.8rem 1rem;
      margin-bottom: 1rem;
      border: none;
      border-radius: 8px;
      background-color: whitesmoke;
      color: black;
      font-size: 1rem;
      outline: none;
      transition: all 0.2s ease;
    }

    .login-input:focus {
      background-color: #141414;
      box-shadow: 0 0 0 2px #daaa00;
      color: white;
    }

    button.login_input {
      width: 100%;
      padding: 0.8rem 1rem;
      border: none;
      border-radius: 8px;
      background-color: #cfb991;
      color: white;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: background-color 0.2s ease, transform 0.1s ease;
    }

    button.login_input:hover {
      background-color: #daaa00;
    }

    button.login_input:active {
      transform: scale(0.98);
    }

    .footer {
      background-color: #000;         
      color: whitesmoke;              
      text-align: center;
      padding: 0.5rem 0;
      font-size: 0.9rem;
      position: fixed;                
      bottom: 0;
      left: 0;
      width: 100%;
    }

    .dialogue {
      display: none;
      position: fixed;
      width: 60%;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%); /* Centers the modal */
      
      background: #ffffff;
      height: auto;
      max-height: 70%;
      padding: 2rem;
      
      border-radius: 16px;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);

      color: #222;
      overflow-y: auto;
      
      animation: fadeIn 0.25s ease;
  }

  .dialogue-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(4px);
  }

  /* Simple fade-in animation */
  @keyframes fadeIn {
      from { opacity: 0; transform: translate(-50%, -48%); }
      to { opacity: 1; transform: translate(-50%, -50%); }
  }
  </style>
  </head>

  <body>
  <div class="header">
    <img src="https://assets.streamlinehq.com/image/private/w_300,h_300,ar_1/f_auto/v1/icons/logos/palantir-xdp5vzpsdiuov68zwbgn.png/palantir-4vc1iawq4sqw5r2jh1n7cr.png?_a=DATAg1AAZAA0">
    <h1>Palantir</h1>
    <div class="dummy">

    </div>
  </div>

  <div class="dialogue-backdrop"></div>

  <div class="headerspacer">
    <h1>Palantir</h1>
  </div>
    <div class="content">
      <div class="photoboxwrapper">
        <h2>Meet the Team</h2>
        <div class="photobox">
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
                <img src="Jake Getson Headshot.png">
              </div>
            </div>
            <p>Jake Getson</p>
            <underline></underline>
            <div class="dialogue">Hi I'm Jake!</div>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
              </div>
            </div>
            <p>Alejandro Melendez</p>
            <underline></underline>
            <div class="dialogue">Hi I'm Alejandro!</div>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
                <img src="Erin Grudis Headshot.png">
              </div>
            </div>
            <p>Erin Grudis</p>
            <underline></underline>
            <div class="dialogue">Hi im Erin!</div>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
                <img src="Oda Drake Headshot.jpg">
              </div>
            </div>
            <p>Jack Drake</p>
            <underline></underline>
            <div class="dialogue">Hi im jack</div>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
                <img src="Calista Martin Headshot.jpg">
              </div>
            </div>
            <p>Calista Martin</p>
            <underline></underline>
            <div class="dialogue">Hi im Calista</div>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
                <img src="Jose Cochon Headshot.jpg">
              </div>
            </div>
            <p>Jose Cochon</p>
            <underline></underline>
            <div class="dialogue">Hi im Jose</div>
          </div>
        </div>
      </div>

      <!-- Login -->
      <form action="index.php" method="post">
        <div id="logincontainer">
          <!-- Title & Logo -->
          <h2>Login</h2>

          <!-- Username & Password -->
          <input class="login-input" type="text" placeholder="Username" name="username">
          <input class="login-input" type="password" placeholder="Password" name="password">

          <button class="login_input" type="submit">Login</button>
        </div>
      </form>

      <!-- Help circle -->
      <div class="circle">
        ?
      <underline></underline>
      </div>

      <footer class="footer">
        <?php echo date("F j, Y"); ?>
      </footer>

  <script>
    $(document).ready(() => {
      const $backdrop = $(".dialogue-backdrop");

      $("body").on("click", (event) => {
        const $target = $(event.target);
        console.log(event.target);

        // 1) Click on a headshot image → open that person's dialogue + show backdrop
        if ($target.is(".square img")) {
          // hide any open dialogues first
          $(".dialogue").hide();

          // find the dialogue in the same card
          const $dialogue = $target.closest(".card").children(".dialogue");
          $dialogue.show();

          // show backdrop
          $backdrop.show();
          return;
        }

        // 2) Click inside an open dialogue → do nothing (keep it open)
        if ($target.is(".dialogue") || $target.closest(".dialogue").length) {
          return;
        }

        // 3) Click on the backdrop → close everything
        if ($target.is(".dialogue-backdrop")) {
          $(".dialogue").hide();
          $backdrop.hide();
          return;
        }

        // 4) Click anywhere else on the page → close everything
        $(".dialogue").hide();
        $backdrop.hide();
      });

      // Optional: ESC key closes dialogue + backdrop
      $(document).on("keydown", (e) => {
        if (e.key === "Escape") {
          $(".dialogue").hide();
          $(".dialogue-backdrop").hide();
        }
      });
    });
  </script>
  
  </body>
</html>
